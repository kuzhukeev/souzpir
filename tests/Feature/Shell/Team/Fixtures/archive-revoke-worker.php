<?php

use App\Models\User;
use App\Shell\Organizations\Http\OrganizationController;
use App\Shell\Team\InvitationRevocation;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\IsolatedAuthDatabase;

require dirname(__DIR__, 5).'/vendor/autoload.php';
$app = require dirname(__DIR__, 5).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
IsolatedAuthDatabase::assert();
$identity = DB::selectOne('select current_database() as db, current_user as role, host(inet_server_addr()) as host, inet_server_port() as port');
if (realpath(base_path()) !== realpath(getcwd()) || $identity->db !== getenv('E106_TEST_DB')
    || $identity->role !== getenv('E106_TEST_ROLE') || $identity->host !== '127.0.0.1'
    || (int) $identity->port !== (int) getenv('E106_TEST_PORT')) {
    throw new LogicException('Non-isolated race test connection.');
}
Mail::fake();
Http::fake();
$user = User::findOrFail(getenv('E106_ACTOR'));
$session = $app['session']->driver();
$session->setId(getenv('E106_SESSION'));
$session->start();
$session->put('auth.version', $user->auth_version);
$mode = getenv('E106_MODE');
$request = $mode === 'archive'
    ? Request::create('/brands/test/archive', 'PATCH', ['confirm_name' => getenv('E106_BRAND_NAME')])
    : Request::create('/team/invitations/test/revoke', 'POST');
$request->setLaravelSession($session);
$request->setUserResolver(fn () => $user);
$request->attributes->set('tenant.brand_revision', getenv('E106_REVISION'));
$request->headers->set('X-Brand-Revision', getenv('E106_HEADER_REVISION'));
Auth::setUser($user);
$context = new TenantContext(getenv('E106_ORG'), getenv('E106_SELECTED_BRAND'), $user->id, getenv('E106_MEMBERSHIP'));
fwrite(STDOUT, "ready\n");
try {
    app(TenantContextRunner::class)->run($context, function () use ($mode, $request): void {
        if ($mode === 'archive') {
            app(OrganizationController::class)->archiveBrand($request, getenv('E106_TARGET_BRAND'));
            fwrite(STDOUT, "archived\n");
        } elseif ($mode === 'revoke') {
            app(InvitationRevocation::class)->revoke($request, getenv('E106_INVITATION'));
            fwrite(STDOUT, "revoked\n");
        } else {
            throw new LogicException('Unknown worker mode.');
        }
    });
} catch (AuthorizationException) {
    fwrite(STDOUT, "denied:403\n");
} catch (ModelNotFoundException) {
    fwrite(STDOUT, "denied:404\n");
} catch (HttpException $exception) {
    fwrite(STDOUT, 'denied:'.$exception->getStatusCode()."\n");
} catch (Throwable $exception) {
    fwrite(STDOUT, 'error:'.$exception::class."\n");
    exit(1);
}
Mail::assertNothingSent();
Http::assertNothingSent();
