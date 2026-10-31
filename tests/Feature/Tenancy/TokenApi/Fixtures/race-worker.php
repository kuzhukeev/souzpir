<?php

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require dirname(__DIR__, 5).'/vendor/autoload.php';
$app = require dirname(__DIR__, 5).'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
$actual = DB::selectOne('select current_database() as name, current_user as role');
if (! $app->environment('testing') || $actual->name !== config('runtime.test_database')
    || $actual->name === config('runtime.preview_database') || ! preg_match('/(?:^|_)test(?:_|$)/', $actual->name)) {
    throw new RuntimeException('Refusing non-test database.');
}

$org = getenv('API_RACE_ORG');
$brand = getenv('API_RACE_BRAND');
$tokenId = getenv('API_RACE_TOKEN_ID');
$marker = getenv('API_RACE_MARKER');
if (getenv('API_RACE_MODE') === 'revoke') {
    $pid = DB::selectOne('select pg_backend_pid() as pid')->pid;
    fwrite(STDOUT, 'revoke-ready:'.$pid."\n");
    DB::transaction(function () use ($org, $brand, $tokenId): void {
        DB::table('organizations')->where('id', $org)->lockForUpdate()->first();
        DB::table('brands')->where('id', $brand)->lockForUpdate()->first();
        DB::table('personal_access_tokens')->where('id', $tokenId)->delete();
    });
    fwrite(STDOUT, "revoked\n");
    exit;
}

DB::listen(function ($query): void {
    if (str_contains($query->sql, 'personal_access_tokens') && str_contains($query->sql, 'tokenable_type')
        && str_starts_with(strtolower($query->sql), 'select')) {
        fwrite(STDOUT, "guard-ready\n");
    }
});
Route::middleware(['api', 'auth:sanctum', 'tenant.token', 'token.ability:read'])
    ->post('/api/v1/race-probe', function () use ($brand, $marker) {
        fwrite(STDOUT, "action-started\n");
        $deadline = microtime(true) + 10;
        while (! is_file($marker) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! is_file($marker)) {
            throw new RuntimeException('Release timed out.');
        }
        DB::table('brands')->where('id', $brand)->update(['name' => 'Committed race fixture']);

        return response()->json(['ok' => true]);
    });
$request = Request::create('http://127.0.0.1/api/v1/race-probe', 'POST', [], [], [], [
    'REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1', 'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer '.getenv('API_RACE_TOKEN'),
]);
$response = $app->make(HttpKernel::class)->handle($request);
fwrite(STDOUT, 'status:'.$response->getStatusCode()."\n");
