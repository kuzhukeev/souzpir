<?php

declare(strict_types=1);

require __DIR__.'/../bootstrap.php';
$args = getopt('', ['prototype:', 'output:']);
$app = baselineBoot($args['prototype'] ?? throw new RuntimeException('prototype required'));
require __DIR__.'/../FixtureClient.php';
require __DIR__.'/../ContactNormalizer.php';
require __DIR__.'/../RecordedClient.php';

use Souzpir\Baseline\FixtureClient;
use Souzpir\Baseline\ContactNormalizer;
use Souzpir\Baseline\RecordedClient;

$output = $args['output'] ?? throw new RuntimeException('output required');
if (file_exists($output)) {
    throw new RuntimeException('Output exists.');
}
mkdir($output, 0700, true);
$checks = 0;
function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($label); }
    $checks++;
}
function refuses(Closure $fn, string $label): void {
    try { $fn(); } catch (RuntimeException|InvalidArgumentException) { check(true, $label); return; }
    throw new RuntimeException($label);
}
$original = ['persona' => ['email' => 'first@example.org', 'alternate' => 'second@example.net']];
$n = new ContactNormalizer;
$n->assertOriginal($original);
$seed = $n->normalize(FixtureClient::canonical($original));
check($seed['persona']['email'] !== $seed['persona']['alternate'], 'distinct contacts');
check(filter_var($seed['persona']['email'], FILTER_VALIDATE_EMAIL) !== false, 'valid email slot');
check($n->normalize($seed) === $seed, 'idempotent aliases');
$alias = $seed['persona']['email'];
foreach (['**', chr(96), "'"] as $wrapper) {
    $wrapped = $wrapper.$alias.$wrapper;
    check($n->normalize($wrapped) === $wrapped, 'issued alias preserves paired wrapper');
    check($n->normalize('Почта: '.$wrapped.'.') === 'Почта: '.$wrapped.'.', 'paired wrapper with sentence punctuation');
    check($n->normalize(['nested'=>['text'=>$wrapped]]) === ['nested'=>['text'=>$wrapped]], 'nested wrapper retained');
    $json = json_encode(['text'=>$wrapped], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    check($n->normalize($json) === $json, 'JSON string wrapper retained');
    check($n->normalize($wrapped, true) === $wrapped, 'issued wrapped replay alias idempotent');
    check($n->normalize($alias.$wrapper) === $alias.$wrapper, 'existing bare alias followed by punctuation unchanged');
    refuses(fn () => (new ContactNormalizer)->normalize($wrapped), 'wrapped unissued alias rejected');
    refuses(fn () => (new ContactNormalizer)->normalize($wrapped, true), 'wrapper does not mint replay alias');
    refuses(fn () => $n->normalize($wrapper.'contact-9999@baseline.invalid'.$wrapper), 'wrapped unknown alias rejected');
    foreach ([
        $wrapper.$alias,
        'x'.$wrapped, $wrapped.'x', $wrapped.'@example.test', $wrapped.'.example',
        '+'.$wrapped, $wrapped.'+tag', $wrapper.'x'.$alias.$wrapper,
        $wrapper.'prefix+'.$alias.$wrapper, $wrapper.$alias.'.example'.$wrapper,
        $wrapper.$alias.'@example.test'.$wrapper,
        $wrapper.$wrapped.$wrapper, chr(92).$wrapped, $wrapper.strtoupper($alias).$wrapper,
    ] as $collisionIndex => $collision) {
        refuses(fn () => $n->normalize($collision), 'malformed wrapper or substring collision rejected '.$collisionIndex);
    }
}
check($n->normalize('**'.$alias.'**, '.chr(96).$seed['persona']['alternate'].chr(96)) ===
    '**'.$alias.'**, '.chr(96).$seed['persona']['alternate'].chr(96), 'two distinct issued aliases retain identity');
refuses(fn () => (new ContactNormalizer)->assertOriginal(['email' => 'contact-0001@baseline.invalid']), 'reserved original namespace');
refuses(fn () => (new ContactNormalizer)->normalize('contact-0001@baseline.invalid'), 'unissued alias rejected');
refuses(fn () => (new ContactNormalizer)->normalize('contact-0002@baseline.invalid', true), 'out of order replay alias rejected');
check($n->normalize(['password'=>'private','authorization'=>'Bearer private']) === ['password'=>'[REDACTED]','authorization'=>'[REDACTED]'], 'secrets');
check($n->normalize('{"api-key":"secret","nested":{"client-secret":"secret"}}') === '{"api-key":"[REDACTED]","nested":{"client-secret":"[REDACTED]"}}', 'nested hyphenated secrets');
refuses(fn () => $n->normalize(['content'=>'Телефон +7 (999) 123-45-67']), 'phone output fails closed');
check($n->normalize(['amount'=>'500000','id'=>'gen-123456789012345']) === ['amount'=>'500000','id'=>'gen-123456789012345'], 'amount and receipt ID preserved');
refuses(fn () => new RecordedClient(new ContactNormalizer, $output.'/not-created', 'capture', 'x', 'x', 'dialogue', fn()=>[]), 'fake cannot claim real provenance');
$requests = [];
$broker = static function (array $call) use (&$requests): array {
    $requests[] = $call;
    $i = count($requests);
    return [
        'ok'=>true, 'request_body'=>$call['body'] + ['stream'=>false, 'plugins'=>[], 'provider'=>['allow_fallbacks'=>false, 'max_price'=>['prompt'=>1.0, 'completion'=>1.0]]], 'captured_at'=>'2026-10-31T09:00:00Z',
        'data'=>[
            'id'=>'fake-'.$i, 'model'=>'test/model',
            'choices'=>[['message'=>[
                'reasoning'=>"Почта 'contact-0001@baseline.invalid' уже известна.",
                'content'=>$i === 1 ? 'Отправим **contact-0002@baseline.invalid**, копия '.chr(96).'contact-0001@baseline.invalid'.chr(96).'.' : '{"email":"second@example.net","approved":true}',
                'tool_calls'=>$i === 1 ? [['id'=>'tool-1','type'=>'function','function'=>['name'=>'send_price','arguments'=>'{"email":"second@example.net","copy":"first@example.org","note":"**contact-0001@baseline.invalid**","amount":1.0}']]] : [],
            ],'finish_reason'=>'stop']],
            'usage'=>['prompt_tokens'=>20,'completion_tokens'=>10,'total_tokens'=>30,'cost'=>0.0003123456789012345],
        ],
    ];
};
$options = ['model'=>'test/model','component'=>'responder','temperature'=>0.0,'max_tokens'=>200,
    'tools'=>[['type'=>'function','function'=>['name'=>'test_empty','parameters'=>['type'=>'object','properties'=>new stdClass]]]],
];
function turns(RecordedClient $client, array $options): array {
    $a = $client->chat([['role'=>'user','content'=>'first@example.org и second@example.net']], $options + ['lead_id'=>123]);
    $b = $client->chat([
        ['role'=>'assistant','content'=>$a->content],
        ['role'=>'tool','content'=>json_encode($a->toolCalls[0]['arguments'], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)],
    ], $options + ['lead_id'=>999]);
    return [get_object_vars($a), get_object_vars($b)];
}
$client = new RecordedClient($n, $output.'/calls', 'test', 'offline-test', 'manual.fake', 'dialogue', $broker);
$trace = turns($client, $options);
$client->assertExhausted();
check(count($requests) === 2, 'two broker calls');
check(!str_contains(FixtureClient::json($requests), 'example.org'), 'wire contact redacted');
check($trace[0]['toolCalls'][0]['arguments']['email'] !== $trace[0]['toolCalls'][0]['arguments']['copy'], 'tool identities retained');
check($trace[0]['toolCalls'][0]['arguments']['amount'] === 1.0, 'numeric type retained');
check(!str_contains(file_get_contents($output.'/calls/0001.json'), 'example.net'), 'no raw address on disk');
for ($i = 0; $i < 2; $i++) {
    $normalizer = new ContactNormalizer;
    $normalizer->normalize(FixtureClient::canonical($original));
    $replay = new RecordedClient($normalizer, $output.'/calls', 'replay', 'offline-test', 'manual.fake');
    $again = turns($replay, $options);
    $replay->assertExhausted();
    check(FixtureClient::json($trace) === FixtureClient::json($again), 'multiturn replay exact');
}
refuses(fn () => new RecordedClient(new ContactNormalizer, $output.'/calls', 'test', 'x', 'x'), 'no overwrite');
$bad = new RecordedClient(new ContactNormalizer, $output.'/calls', 'replay', 'x', 'x');
refuses(fn () => $bad->chat([['role'=>'user','content'=>'different']], $options), 'semantic mismatch');
$missing = new RecordedClient(new ContactNormalizer, $output.'/absent', 'replay', 'x', 'x');
refuses(fn () => $missing->chat([], $options), 'missing never falls back');
$unconsumed = new RecordedClient(new ContactNormalizer, $output.'/calls', 'replay', 'x', 'x');
refuses(fn () => $unconsumed->assertExhausted(), 'unconsumed rejected');
foreach (['curl_exec','curl_multi_exec','fsockopen','pfsockopen','stream_socket_client','mail'] as $function) {
    check(!function_exists($function), 'network/mail disabled');
}
check(!ini_get('allow_url_fopen'), 'URL streams disabled');
$raw = json_decode(file_get_contents($output.'/calls/0001.json'), true, 512, JSON_THROW_ON_ERROR);
check($raw['provenance'] === 'synthetic', 'fake never recorded');
check($raw['evidence']['usage']['cost_usd'] === json_encode($raw['response']['raw']['usage']['cost'], JSON_PRESERVE_ZERO_FRACTION), 'cost retains full JSON precision');
check($raw['evidence']['request_body']['provider']['max_price']['prompt'] === 1.0, 'actual price-controlled wire stored');
check($raw['evidence']['request_body']['plugins'] === [], 'actual plugin controls stored');
check($raw['response']['raw']['choices'][0]['message']['reasoning'] === "Почта 'contact-0001@baseline.invalid' уже известна.", 'raw reasoning retains paired known alias');
$unknownBroker = static function (array $call): array {
    return ['ok'=>true, 'request_body'=>$call['body'], 'captured_at'=>'2026-10-31T09:00:00Z',
        'data'=>['id'=>'fake-unknown', 'model'=>'test/model',
            'choices'=>[['message'=>['content'=>'Ответ', 'reasoning'=>'**contact-9999@baseline.invalid**']]],
            'usage'=>['prompt_tokens'=>20,'completion_tokens'=>10,'total_tokens'=>30,'cost'=>0.001]]];
};
$unknown = new RecordedClient(new ContactNormalizer, $output.'/unknown', 'test', 'unknown', 'manual.fake', 'dialogue', $unknownBroker);
refuses(fn () => $unknown->chat([['role'=>'user','content'=>'first@example.org']], $options), 'unknown wrapped reasoning refuses complete response');
check(!file_exists($output.'/unknown/0001.json'), 'unknown reserved output writes no response evidence');
$wrongNormalizer = new ContactNormalizer;
$wrongNormalizer->normalize(FixtureClient::canonical($original));
$wrongCase = new RecordedClient($wrongNormalizer, $output.'/calls', 'replay', 'offline-test', 'manual.wrong_case');
refuses(fn () => turns($wrongCase, $options), 'another case cannot consume same recording');
$anthropic = RecordedClient::wire(['messages'=>[['role'=>'system','content'=>'System']], 'options'=>[
    'model'=>'anthropic/test','response_format'=>['json_schema'=>['schema'=>['type'=>'object']]],
]]);
check(str_starts_with($anthropic['messages'][1]['content'], 'Ответь строго одним JSON-объектом'), 'donor anthropic adaptation UTF8');
check(!isset($anthropic['response_format']) && str_contains($anthropic['messages'][1]['content'], ' по схеме: '), 'donor schema appended as text');

$tool = ['type'=>'function','function'=>['name'=>'send_price','description'=>'Send once','parameters'=>['type'=>'object','properties'=>new stdClass]]];
$nineTools = [];
foreach (range(0, 6) as $index) {
    $unique = $tool;
    $unique['function']['name'] = 'action_'.$index;
    $nineTools[] = $unique;
}
array_splice($nineTools, 6, 0, [$tool]);
$nineTools[] = $tool;
$duplicateOptions = ['model'=>'test/model','tools'=>$nineTools];
$duplicateBroker = static function (array $call): array {
    $wire = $call['body'];
    $wire['tools'] = array_slice($wire['tools'], 0, 8);
    return ['ok'=>true,'captured_at'=>'2026-10-31T09:00:00Z','request_body'=>$wire,
        'data'=>['id'=>'fake-dedup','model'=>'test/model',
            'choices'=>[['message'=>['content'=>null,'tool_calls'=>[
                ['id'=>'invoke-1','type'=>'function','function'=>['name'=>'send_price','arguments'=>'{"amount":1}']],
                ['id'=>'invoke-2','type'=>'function','function'=>['name'=>'send_price','arguments'=>'{"amount":2}']],
            ]]]],
            'usage'=>['prompt_tokens'=>20,'completion_tokens'=>10,'total_tokens'=>30,'cost'=>0.0001]]];
};
$dedup = new RecordedClient(new ContactNormalizer, $output.'/dedup', 'test', 'dedup', 'manual.fake', 'dialogue', $duplicateBroker);
$dedupResult = $dedup->chat([['role'=>'user','content'=>'Continue']], $duplicateOptions);
$dedup->assertExhausted();
$dedupCall = json_decode(file_get_contents($output.'/dedup/0001.json'), true, 512, JSON_THROW_ON_ERROR);
check(count($dedupCall['request']['options']['tools']) === 9, 'original donor duplicate definitions preserved');
check(count($dedupCall['evidence']['request_body']['tools']) === 8, 'actual physical wire keeps eight stable definitions');
check(count($dedupResult->toolCalls) === 2, 'response tool invocations are never deduplicated');
$dedupReplay = new RecordedClient(new ContactNormalizer, $output.'/dedup', 'replay', 'dedup', 'manual.fake');
$dedupAgain = $dedupReplay->chat([['role'=>'user','content'=>'Continue']], $duplicateOptions);
$dedupReplay->assertExhausted();
check(FixtureClient::json(get_object_vars($dedupAgain)) === FixtureClient::json(get_object_vars($dedupResult)), 'deduplicated physical wire replays original donor request');
$conflictOptions = $duplicateOptions;
$conflictOptions['tools'][8]['function']['description'] = 'Different behavior';
$conflict = new RecordedClient(new ContactNormalizer, $output.'/conflict', 'test', 'conflict', 'manual.fake', 'dialogue', $duplicateBroker);
refuses(fn () => $conflict->chat([['role'=>'user','content'=>'Continue']], $conflictOptions), 'conflicting definitions fail closed');
check(!file_exists($output.'/conflict/0001.json'), 'conflicting tool evidence is not written');

echo json_encode(['checks'=>$checks,'real_llm_calls'=>0,'replays'=>2,'result'=>'passed'])."\n";
