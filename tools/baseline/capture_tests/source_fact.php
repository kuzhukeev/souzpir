<?php

declare(strict_types=1);

namespace Souzpir\Baseline {
    // Simulate a mismatching read fingerprint without changing donor source bytes.
    function hash_file(string $algorithm, string $filename): string|false {
        return ($GLOBALS['baseline_test_bad_pin'] ?? false)
            ? str_repeat('0', 64) : \hash_file($algorithm, $filename);
    }
}

namespace {
require __DIR__.'/../bootstrap.php';
$args = getopt('', ['prototype:', 'output:']);
$app = baselineBoot($args['prototype'] ?? throw new RuntimeException('prototype required'));
require __DIR__.'/../FixtureClient.php';
require __DIR__.'/../ContactNormalizer.php';
require __DIR__.'/../RecordedClient.php';

use App\Agent\Goal;
use App\Agent\Knowledge;
use App\Agent\LeadState;
use App\Agent\Responder;
use Souzpir\Baseline\ContactNormalizer;
use Souzpir\Baseline\FixtureClient;
use Souzpir\Baseline\RecordedClient;

$output = $args['output'] ?? throw new RuntimeException('output required');
if (file_exists($output)) { throw new RuntimeException('Output exists.'); }
mkdir($output, 0700, true);
$checks = 0;
function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($label); }
    $checks++;
}
function refuses(Closure $operation, string $label): void {
    try { $operation(); } catch (RuntimeException) { check(true, $label); return; }
    throw new RuntimeException($label);
}
$knowledge = new Knowledge(realpath($args['prototype']).'/resources/knowledge', 0);
$fact = $knowledge->fact('too_early_response');
$until = (string) $knowledge->conditions()['promotions'][0]['until'];
$goal = new Goal('handle_objection', facts:[$fact]);
$prompt = app(Responder::class)->systemPrompt(new LeadState, $goal);
$messages = [['role'=>'system','content'=>$prompt], ['role'=>'user','content'=>'Закупать ещё рано.']];
$options = ['model'=>config('agent.models.responder'),'component'=>'responder','max_tokens'=>1200,'temperature'=>0.5];
$n = new ContactNormalizer;
$request = $n->request($messages, $options);
check($request === FixtureClient::canonical(['messages'=>$messages,'options'=>$options]), 'frozen source fact preserves exact request');
check(RecordedClient::wire($request)['messages'][0]['content'] === $prompt, 'exact original wire content retained');
check(substr_count($prompt, $until) === 1, 'actual donor timestamp appears once');
check($goal->toArray()['facts'] === ['too_early_response'], 'actual Goal serializes only fact IDs');
$trace = ['turns'=>[['meta'=>['goal'=>$goal->toArray()], 'reply'=>'Когда планируете закупку?']]];
check($n->normalize($trace) === $trace, 'actual Goal trace requires no exception');
refuses(fn () => $n->normalize(['turns'=>[['meta'=>['goal'=>['facts'=>[$fact]]]]]]), 'full fact object cannot bypass generic trace guard');
refuses(fn () => $n->normalize(['raw'=>['choices'=>[['message'=>['content'=>$until]]]]]), 'provider timestamp remains guarded');
refuses(fn () => $n->normalize($prompt), 'generic content has no source exemption');
foreach (['user','assistant','tool'] as $role) {
    refuses(fn () => $n->request([['role'=>$role,'content'=>$prompt]], $options), 'other message roles remain guarded');
}
refuses(fn () => $n->request([['role'=>'user','content'=>'Текст'], ['role'=>'system','content'=>$prompt]], $options), 'moved system message remains guarded');
foreach (['extractor','simulator','guard',null] as $component) {
    $changed = $options;
    if ($component === null) { unset($changed['component']); } else { $changed['component']=$component; }
    refuses(fn () => $n->request($messages, $changed), 'other components remain guarded');
}
foreach ([
    $prompt."\nТелефон +7 (999) 123-45-67",
    $prompt."\n".$until,
    str_replace($until, '79991234567', $prompt),
    str_replace('[too_early_response]', '[untrusted_fact]', $prompt),
    str_replace('ФАКТЫ, НА КОТОРЫЕ МОЖНО ОПИРАТЬСЯ', 'Произвольный текст', $prompt),
] as $changed) {
    refuses(fn () => $n->request([['role'=>'system','content'=>$changed]], $options), 'unrelated or forged number rejected');
}
$second = $messages;
$second[1]['content'] .= ' '.$until;
refuses(fn () => $n->request($second, $options), 'known timestamp in user text rejected');
$GLOBALS['baseline_test_bad_pin'] = true;
refuses(fn () => (new ContactNormalizer)->request($messages, $options), 'changed source fingerprint rejected');
$GLOBALS['baseline_test_bad_pin'] = false;

$broker = static fn (array $call): array => [
    'ok'=>true,'request_body'=>$call['body'],'captured_at'=>'2026-10-31T09:00:00Z',
    'data'=>['id'=>'fake-source-fact','model'=>config('agent.models.responder'),
        'choices'=>[['message'=>['content'=>'Понимаю. Когда планируете закупку?']]],
        'usage'=>['prompt_tokens'=>100,'completion_tokens'=>10,'total_tokens'=>110,'cost'=>0.00001]],
];
$client = new RecordedClient(new ContactNormalizer, $output.'/calls', 'test', 'source_fact', 'manual.source_fact', 'dialogue', $broker);
$result = $client->chat($messages, $options);
$client->assertExhausted();
$record = json_decode(file_get_contents($output.'/calls/0001.json'), true, 512, JSON_THROW_ON_ERROR);
check($record['provenance'] === 'synthetic', 'fake source proof remains synthetic');
check($record['evidence']['request_body']['messages'][0]['content'] === $prompt, 'saved actual wire remains exact');
for ($pass=0;$pass<2;$pass++) {
    $replay = new RecordedClient(new ContactNormalizer, $output.'/calls', 'replay', 'source_fact', 'manual.source_fact');
    $again = $replay->chat($messages, $options);
    $replay->assertExhausted();
    check(FixtureClient::json(get_object_vars($again)) === FixtureClient::json(get_object_vars($result)), 'frozen source replay remains exact');
}
echo json_encode(['checks'=>$checks,'real_llm_calls'=>0,'replays'=>2,'result'=>'passed'])."\n";
}
