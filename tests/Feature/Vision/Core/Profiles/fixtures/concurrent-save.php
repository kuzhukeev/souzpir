<?php

use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\Profiles\Questionnaire;
use App\Vision\Core\Profiles\QuizConflict;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$root = dirname(__DIR__, 6);
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$testDb = config('runtime.test_database');
$state = DB::selectOne('select current_database() as db, current_user as role, inet_server_addr() as host, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
if (str_replace('\\', '/', base_path()) !== str_replace('\\', '/', $root) || ! is_string($testDb)
    || ! preg_match('/(?:^|_)test(?:_|$)/', $testDb) || $testDb === config('runtime.preview_database') || $state->db !== $testDb
    || (! str_starts_with((string) $state->host, '127.') && $state->host !== '::1') || (int) $state->port !== (int) config('database.connections.pgsql.port')
    || $state->rolsuper || $state->rolcreatedb || $state->rolcreaterole) {
    throw new RuntimeException('Wrong test database.');
}
Http::preventStrayRequests();
[$unused, $userId, $orgId, $brandId, $memberId, $quizId, $marker] = $argv;
if (! preg_match('/^[a-f0-9]{24}$/D', $marker)) {
    throw new RuntimeException('Invalid marker.');
}
$user = User::findOrFail($userId);
session()->start();
session()->regenerate(true);
session()->put('auth.version', $user->auth_version);
session()->put('brand_id', $brandId);
$request = Request::create('/vision/quiz', 'POST');
$request->setLaravelSession(app('session.store'));
$request->setUserResolver(fn () => $user);
app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
$tenant = new TenantContext($orgId, $brandId, $userId, $memberId);
file_put_contents($root.'/.local/runtime/testing/'.$marker.'.ready', 'ready');
try {
    app(TenantContextRunner::class)->run($tenant, fn () => app(Questionnaire::class)->saveStep($request, $quizId, 1, 1, ['brand_name' => $marker]));
    echo 'saved';
} catch (QuizConflict) {
    echo 'conflict';
}
