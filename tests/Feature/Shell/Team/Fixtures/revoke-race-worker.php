<?php

use App\Models\User;
use App\Shell\Team\InvitationRevocation;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 5).'/vendor/autoload.php';
$app = require dirname(__DIR__, 5).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== getenv('E106_TEST_DB')
    || ! str_starts_with((string) getenv('E106_TEST_DB'), 'souzpir_test_')
    || config('runtime.preview_database') === getenv('E106_TEST_DB')) {
    throw new LogicException('Non-isolated test database.');
}
$user = User::findOrFail(getenv('E106_ACTOR'));
$session = $app['session']->driver();
$session->setId(getenv('E106_SESSION'));
$session->start();
$session->put('auth.version', $user->auth_version);
$request = Request::create('/team/invitations/test/revoke', 'POST');
$request->setLaravelSession($session);
$request->setUserResolver(fn () => $user);
$request->attributes->set('tenant.brand_revision', getenv('E106_REVISION'));
Auth::setUser($user);
$context = new TenantContext(getenv('E106_ORG'), getenv('E106_BRAND'), $user->id, getenv('E106_MEMBERSHIP'));
fwrite(STDOUT, "ready\n");
try {
    app(TenantContextRunner::class)->run($context, fn () => app(InvitationRevocation::class)->revoke($request, getenv('E106_INVITATION')));
    fwrite(STDOUT, "revoked\n");
} catch (HttpException $exception) {
    fwrite(STDOUT, 'denied:'.$exception->getStatusCode()."\n");
} catch (ModelNotFoundException) {
    fwrite(STDOUT, "denied:404\n");
} catch (Throwable $exception) {
    fwrite(STDOUT, 'error:'.$exception::class."\n");
    exit(1);
}
