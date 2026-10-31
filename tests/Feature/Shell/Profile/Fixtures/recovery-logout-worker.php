<?php

use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\Http\SessionController;
use App\Shell\Auth\MfaCode;
use App\Shell\Auth\Totp;
use App\Shell\Profile\Security\MfaRecoveryController;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = basename(dirname(base_path())) === '.worktrees' ? 'souzpir_test_'.str_replace('-', '_', basename(base_path())) : 'souzpir_test_main';
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $expected
    || DB::connection()->getDatabaseName() !== config('runtime.test_database')
    || DB::connection()->getDatabaseName() === config('runtime.preview_database')) {
    throw new LogicException('Isolated test database required.');
}
$data = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
$user = User::findOrFail($data['user']);
$session = app('session')->driver();
$session->setId($data['session']);
$session->start();
$session->put('auth.version', $data['version']);
Auth::guard()->setUser($user);
$request = Request::create('https://souzpir.local/'.($data['operation'] === 'issue' ? 'profile/security/mfa/recovery' : 'logout'), 'POST');
$request->setLaravelSession($session);
$request->setUserResolver(fn () => $user);
if ($data['operation'] === 'issue') {
    $code = app(Totp::class)->at('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', intdiv(now()->timestamp, 30));
    $request->attributes->set('mfa.safe_code', MfaCode::parse($code));
    unset($code);
}
DB::statement("SET lock_timeout = '15s'");
echo 'ready:'.DB::selectOne('select pg_backend_pid() as pid')->pid."\n";
flush();
try {
    if ($data['hold']) {
        DB::beginTransaction();
    }
    if ($data['operation'] === 'issue') {
        $response = app(TenantContextRunner::class)->run(
            new TenantContext($data['organization'], $data['brand'], $data['user'], $data['membership']),
            fn () => app(MfaRecoveryController::class)->issue($request)
        );
        $body = $response->getData(true);
        if ($response->getStatusCode() !== 200 || ! is_array($body['codes'] ?? null) || count($body['codes']) !== 10) {
            throw new RuntimeException('Unexpected issue result.');
        }
        unset($body, $response);
    } elseif ($data['operation'] === 'logout') {
        $response = app(SessionController::class)->destroy($request);
        if ($response->getStatusCode() !== 302) {
            throw new RuntimeException('Unexpected logout result.');
        }
    } else {
        app(AuthSessionRegistry::class)->withinActive($request, static fn () => null);
    }
    echo "applied\n";
    flush();
    if ($data['hold']) {
        stream_set_timeout(STDIN, 20);
        if (trim((string) fgets(STDIN)) !== 'continue') {
            throw new RuntimeException('Barrier timeout.');
        }
        DB::commit();
    }
    echo "committed\n";
} catch (AuthenticationException) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    echo "denied\n";
} catch (Throwable $error) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDERR, get_class($error)."\n");
    echo "failed\n";
    exit(1);
}
