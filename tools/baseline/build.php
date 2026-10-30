<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';
$args = getopt('', ['prototype:', 'output:']);
if (!isset($args['prototype'], $args['output'])) {
    fwrite(STDERR, "Usage: php [offline flags] tools/baseline/build.php --prototype=.local/prototype --output=.local/baseline-new\n");
    exit(2);
}
$prototype = realpath($args['prototype']);
$output = rtrim($args['output'], '/\\');
if (file_exists($output)) {
    throw new RuntimeException('Output already exists. Evidence is never overwritten; generate to a new directory and review a diff.');
}
$app = baselineBoot($args['prototype']);
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
});
require __DIR__.'/FixtureClient.php';
use App\Agent\Extractor;
use App\Agent\Guard;
use App\Agent\Knowledge;
use App\Agent\LeadState;
use App\Agent\Llm\FakeLlmClient;
use App\Agent\Llm\LlmClient;
use App\Agent\Orchestrator;
use App\Agent\Policy;
use App\Agent\Responder;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Souzpir\Baseline\FixtureClient;

function writeEvidence(string $path, mixed $data): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    if (file_put_contents($path, FixtureClient::json(FixtureClient::redact($data)), LOCK_EX) === false) {
        throw new RuntimeException('Cannot write evidence.');
    }
}

function extraction(array $override): array
{
    return $override + ['slot_updates'=>[], 'client_intent'=>'answer_only', 'client_questions'=>[], 'objections'=>[],
        'sentiment'=>'neutral', 'wants_only_prices'=>false, 'asked_if_bot'=>false, 'wants_human'=>false,
        'do_not_disturb'=>false, 'off_topic'=>false, 'prompt_injection'=>false, 'closed_topic'=>null,
        'unknown_question'=>null, 'client_claims'=>[], 'refused_slots'=>[], 'confidence'=>0.9];
}

function resetDatabase(): void
{
    if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
        throw new RuntimeException('Baseline database must be SQLite :memory:.');
    }
    Artisan::call('migrate:fresh', ['--force'=>true]);
}

$kb = new Knowledge($prototype.'/resources/knowledge', 0);
$policy = new Policy($kb);
$cases = [];
$personas = Symfony\Component\Yaml\Yaml::parseFile($prototype.'/tools/personas.yaml');
if (count($personas) !== 29) {
    throw new RuntimeException('Expected exactly 29 donor personas.');
}
foreach ($personas as $persona) {
    $cases[] = ['id'=>'persona.'.$persona['id'], 'kind'=>'persona', 'title'=>$persona['name'], 'max_turns'=>$persona['max_turns'],
        'expect'=>$persona['expect'], 'provenance'=>'missing', 'fixture'=>null,
        'source'=>'prototype/tools/personas.yaml#'.$persona['id'],
        'reason'=>'No real model recording supplied; no paid simulator run is authorized in SP-T-01.'];
}
$manual = require __DIR__.'/manual-cases.php';
foreach ($manual as $case) {
    $file = 'manual/'.$case['id'].'.json';
    $trace = [];
    if (isset($case['scheduler_hours'])) {
        resetDatabase();
        $start = Carbon::parse('2026-10-31T09:00:00+03:00')->utc();
        Carbon::setTestNow($start);
        $state = new LeadState(['stage'=>'qualify', 'flags'=>['introduced'=>true]]);
        $lead = Lead::create(['channel'=>'test', 'stage'=>'qualify', 'state'=>$state->toArray(), 'last_client_message_at'=>$start->copy()->subMinute()]);
        Message::create(['lead_id'=>$lead->id, 'role'=>'agent', 'text'=>'Подскажите, из какого города?', 'created_at'=>$start]);
        foreach ($case['scheduler_hours'] as $hours) {
            Carbon::setTestNow($start->copy()->addSeconds((int) round($hours * 3600)));
            Artisan::call('followups:send'); // channel=test + empty Telegram token + network disabled
            $fresh = $lead->fresh();
            $trace[] = ['elapsed_hours'=>$hours, 'followups_sent'=>$fresh->state['counters']['followups_sent'],
                'stage'=>$fresh->stage, 'outcome'=>$fresh->outcome,
                'messages'=>Message::where('lead_id', $lead->id)->orderBy('id')->get(['role', 'text', 'meta'])->toArray(),
                'tasks'=>Task::where('lead_id', $lead->id)->orderBy('id')->pluck('type')->all()];
        }
        $layer = 'donor FollowupsSend on synthetic SQLite data; no delivery';
    } else {
        $state = new LeadState(array_replace_recursive(['flags'=>['introduced'=>true]], $case['initial'] ?? []));
        foreach ($case['inputs'] as $input) {
            $x = extraction($input);
            $policy->applyExtraction($state, $x);
            $goal = $policy->decide($state, $x);
            $trace[] = ['extraction'=>$x, 'goal'=>$goal->toArray(), 'state'=>$state->toArray(),
                'outcome'=>$policy->computeOutcome($state)];
        }
        $layer = 'donor Policy on synthetic extraction; no LLM, Guard, tools or delivery';
    }
    writeEvidence($output.'/'.$file, ['schema_version'=>1, 'case_id'=>$case['id'], 'provenance'=>'synthetic',
        'layer'=>$layer, 'input'=>$case, 'trace'=>$trace]);
    $cases[] = ['id'=>$case['id'], 'kind'=>'manual', 'title'=>$case['title'], 'provenance'=>'synthetic',
        'fixture'=>$file, 'source'=>'docs/sources/08_test_plan.md#2.1', 'layer'=>$layer,
        'real_llm_recording'=>'missing'];
}

Carbon::setTestNow('2026-10-31T09:00:00+03:00');
$states = [
    'opening'=>[],
    'newbie_qualified'=>['flags'=>['introduced'=>true], 'slots'=>['city'=>'Тула', 'purpose'=>'на продажу', 'experience'=>'нет']],
    'experienced_price'=>['flags'=>['introduced'=>true], 'slots'=>['city'=>'Казань', 'purpose'=>'на продажу', 'experience'=>'много лет', 'budget_range'=>'100 тыс.']],
    'opt_out'=>['flags'=>['introduced'=>true, 'do_not_disturb'=>true]],
    'deferred_escalation'=>['stage'=>'handoff', 'flags'=>['introduced'=>true, 'escalate'=>true, 'escalate_reason'=>'deferred_insist']],
];
$snapshots = [];
$responder = new Responder(new FakeLlmClient, $kb);
foreach ($states as $id=>$data) {
    $state = new LeadState($data);
    $goal = $policy->decide($state, extraction([]), $id === 'opening');
    $prompts = [
        'extractor'=>view('prompts.extractor', ['card'=>$state->cardText(), 'intents'=>$kb->intents(),
            'closedTopics'=>$kb->closedTopics(), 'objections'=>Extractor::OBJECTIONS, 'agentName'=>config('agent.name')])->render(),
        'responder'=>$responder->systemPrompt($state, $goal),
        'guard'=>view('prompts.guard', ['goal'=>$goal, 'card'=>$state->cardText(), 'facts'=>$goal->facts])->render(),
        'summary'=>view('prompts.summary', ['previous'=>$id === 'opening' ? null : 'Клиент уточняет условия закупки.'])->render(),
    ];
    foreach ($prompts as $template=>$text) {
        $file = 'prompts/'.$id.'/'.$template.'.txt';
        if (!is_dir(dirname($output.'/'.$file))) {
            mkdir(dirname($output.'/'.$file), 0775, true);
        }
        $safe = FixtureClient::redact($text);
        file_put_contents($output.'/'.$file, $safe);
        $snapshots[] = ['state'=>$id, 'template'=>$template, 'path'=>$file, 'sha256'=>hash('sha256', $safe),
            'provenance'=>'rendered_from_donor_with_synthetic_state'];
    }
}
writeEvidence($output.'/prompts/states.json', $states);

function pipeline(Illuminate\Foundation\Application $app, FixtureClient $client, bool $seedFake): array
{
    resetDatabase();
    $app->instance(LlmClient::class, $client);
    $app->forgetInstance(Guard::class);
    $engine = $app->make(Orchestrator::class);
    $lead = Lead::create(['channel'=>'test', 'state'=>[]]);
    if ($seedFake) {
        $client->push('Добрый день. Антон, «Союз Пир». Вы из какого города?');
    }
    $results = [$engine->open($lead)];
    if ($seedFake) {
        $client->push(extraction(['slot_updates'=>['city'=>'Тула', 'purpose'=>'на продажу']]));
        $client->push('Тула, понял. Раньше пиротехникой торговали или первый сезон?');
    }
    $results[] = $engine->turn($lead->fresh(), 'Тула, на продажу');
    return array_map(static function (array $result): array {
        $meta = $result['meta'];
        unset($meta['latency_ms'], $meta['message_id']); // Wall time and DB IDs are not behavior.
        return ['reply'=>$result['reply'], 'meta'=>$meta, 'state'=>$result['lead']->state,
            'stage'=>$result['lead']->stage, 'outcome'=>$result['lead']->outcome];
    }, $results);
}
$record = new FixtureClient($output.'/llm/synthetic_open_turn', 'synthetic');
$recorded = pipeline($app, $record, true);
$replay = new FixtureClient($output.'/llm/synthetic_open_turn');
$replayed = pipeline($app, $replay, false);
$replay->assertExhausted();
if (FixtureClient::json(FixtureClient::redact($recorded)) !== FixtureClient::json(FixtureClient::redact($replayed))) {
    throw new RuntimeException('Synthetic Orchestrator replay differs.');
}
writeEvidence($output.'/synthetic/open-turn.json', ['provenance'=>'synthetic', 'source'=>'prototype/tests/Feature/TurnTest.php',
    'description'=>'Real donor Orchestrator executed with authored fake responses; not real LLM evidence.', 'trace'=>$recorded]);

$sourceFiles = [];
foreach (['app', 'config', 'resources/knowledge', 'resources/views/prompts', 'tests', 'tools'] as $folder) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($prototype.'/'.$folder, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        if ($entry->isFile()) {
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($prototype) + 1));
            $sourceFiles[$relative] = hash_file('sha256', $entry->getPathname());
        }
    }
}
$sourceFiles['composer.lock'] = hash_file('sha256', $prototype.'/composer.lock');
writeEvidence($output.'/source-fingerprints.json', $sourceFiles);
file_put_contents($output.'/.gitattributes', "* text eol=lf\n");
writeEvidence($output.'/manifest.json', ['schema_version'=>1, 'prototype_archive_sha256'=>'7e33e6e2c3a077e081fd736029e8ef4648df0b2cc1d2dffc1a0e4bdb78196c97',
    'coverage'=>['total_cases'=>41, 'personas'=>29, 'manual'=>12, 'recorded_real_llm'=>0, 'synthetic'=>12, 'missing'=>29],
    'frozen_clock'=>'2026-10-31T09:00:00+03:00', 'cases'=>$cases, 'prompt_snapshots'=>$snapshots,
    'equivalence_claim'=>false, 'limitations'=>['29 persona dialogues require separately authorized real capture in SP-T-03.',
        'Manual traces cover Policy and scheduler; full LLM extraction/response quality is not measured.',
        'One synthetic Orchestrator recording/replay proves harness mechanics, not real-model equivalence.']]);
echo json_encode(['result'=>'passed', 'cases'=>count($cases), 'prompt_snapshots'=>count($snapshots),
    'synthetic_orchestrator_replay'=>'identical', 'real_llm_calls'=>0], JSON_UNESCAPED_UNICODE).PHP_EOL;
