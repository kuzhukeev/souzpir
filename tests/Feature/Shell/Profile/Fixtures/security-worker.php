<?php

use App\Models\User;
use App\Shell\Profile\Security\SecuritySettings;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestCase::assertIsolatedTestDatabase();
$payload = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
$user = User::findOrFail($payload['user']);
$session = new Store('concurrency-security', new ArraySessionHandler(120), $payload['session']);
$session->start();
$session->put('auth.version', $user->auth_version);
$request = Request::create('/profile/security/sessions', 'DELETE');
$request->setLaravelSession($session);
$request->setUserResolver(fn () => $user);
$request->attributes->set('tenant.brand_revision', '0');
DB::statement("SET lock_timeout = '8s'");
try {
    if ($payload['hold']) {
        DB::beginTransaction();
        DB::table('auth_session_registry')->where('user_id', $user->id)->orderBy('session_hash')->lockForUpdate()->get();
        echo "locked\n";
        flush();
        stream_set_timeout(STDIN, 15);
        if (trim((string) fgets(STDIN)) !== 'continue') {
            throw new RuntimeException('Barrier timeout.');
        }
    } else {
        echo 'started:'.DB::selectOne('select pg_backend_pid() as pid')->pid."\n";
        flush();
    }
    app(TenantContextRunner::class)->run(
        new TenantContext($payload['organization'], $payload['brand'], $user->id, $payload['membership']),
        fn () => $payload['mode'] === 'all'
            ? app(SecuritySettings::class)->revokeOthers($request)
            : app(SecuritySettings::class)->revoke($request, $payload['target'])
    );
    if ($payload['hold']) {
        DB::commit();
    }
    echo "applied\n";
} catch (AuthenticationException) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    echo "revoked\n";
} catch (Throwable $error) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDERR, get_class($error).': '.$error->getMessage()."\n");
    echo "failed\n";
    exit(1);
}
