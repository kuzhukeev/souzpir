<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';
$args = getopt('', ['prototype:', 'temporary:']);
if (!isset($args['prototype'], $args['temporary']) || file_exists($args['temporary'])) {
    fwrite(STDERR, "Pass --prototype and a new --temporary directory.\n");
    exit(2);
}
baselineBoot($args['prototype']);
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
});
require __DIR__.'/FixtureClient.php';
use Souzpir\Baseline\FixtureClient;
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}
function rejects(callable $action, string $message): void
{
    try {
        $action();
    } catch (RuntimeException) {
        check(true, $message);
        return;
    }
    check(false, $message);
}
$request = [['role'=>'user', 'content'=>'Тест contact@example.invalid sk-example123456789']];
$a = FixtureClient::normalizedRequest($request, ['component'=>'test', 'lead_id'=>1, 'timestamp'=>'yesterday', 'temperature'=>0.2]);
$b = FixtureClient::normalizedRequest($request, ['temperature'=>0.2, 'timestamp'=>'today', 'lead_id'=>2, 'component'=>'test']);
check(FixtureClient::json($a) === FixtureClient::json($b), 'Ordering and bookkeeping should normalize.');
check(FixtureClient::normalizedRequest([['role'=>'user','content'=>'Встреча 2026-11-01']], []) !==
    FixtureClient::normalizedRequest([['role'=>'user','content'=>'Встреча 2026-11-02']], []), 'Semantic dates must not normalize away.');
check(FixtureClient::json(FixtureClient::redact((object) [])) !== FixtureClient::json([]), 'Empty object and list are semantically distinct.');
check(!str_contains(FixtureClient::redact('{"token":"EMBEDDED_SENTINEL"}'), 'EMBEDDED_SENTINEL'), 'Embedded JSON secrets must be redacted.');
$directory = $args['temporary'].'/recording';
$writer = new FixtureClient($directory, 'synthetic');
$writer->push('Ответ без внешнего сервиса.');
$result = $writer->chat($request, ['component'=>'test', 'api_key'=>'SENSITIVE_SENTINEL', 'token'=>'TOKEN_SENTINEL']);
$saved = file_get_contents($directory.'/0001.json');
check(!str_contains($saved, 'SENSITIVE_SENTINEL') && !str_contains($saved, 'TOKEN_SENTINEL'), 'Secret keys must be redacted before writing.');
check(!str_contains($saved, 'contact@example.invalid') && !str_contains($saved, 'sk-example123456789'), 'Embedded secrets and contact must be redacted.');
$reader = new FixtureClient($directory);
$replayed = $reader->chat($request, ['token'=>'different-secret', 'api_key'=>'different-secret', 'component'=>'test']);
check($result->content === $replayed->content, 'Response replay must match.');
$reader->assertExhausted();
check(true, 'All records consumed.');
rejects(fn ()=>$reader->chat($request, []), 'Missing sequence must fail closed.');
rejects(fn ()=>(new FixtureClient($directory))->chat([['role'=>'user','content'=>'Changed input']], []), 'Changed request must fail.');
rejects(fn ()=>(new FixtureClient($directory))->assertExhausted(), 'Unused records must fail.');
rejects(fn ()=>new FixtureClient($directory, 'synthetic'), 'Existing evidence must not be overwritten.');
rejects(fn ()=>new FixtureClient($directory, 'live'), 'Live recording is not supported.');
$invalid = $args['temporary'].'/invalid-provenance';
mkdir($invalid, 0775, true);
$record = json_decode($saved, true, 512, JSON_THROW_ON_ERROR);
$record['provenance'] = 'invented-recording';
file_put_contents($invalid.'/0001.json', FixtureClient::json($record));
rejects(fn ()=>(new FixtureClient($invalid))->chat($request, ['component'=>'test', 'api_key'=>'x', 'token'=>'x']), 'Unknown provenance must fail.');
echo json_encode(['result'=>'passed', 'checks'=>$checks, 'network'=>'disabled', 'real_llm_calls'=>0]).PHP_EOL;
