<?php

use App\Models\User;
use App\Shell\InvitationIssuance\InvitationIssuer;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Shell\InvitationIssuance\Fixtures\IsolatedInvitationDatabase;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    $target = json_decode((string) getenv('E106B_TARGET'), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($target)) {
        throw new LogicException('Expected parent test database identity is required.');
    }
    IsolatedInvitationDatabase::assert($target);
} catch (Throwable) {
    fwrite(STDERR, 'Test database guard rejected the child connection.');
    exit(2);
}
$user = User::findOrFail(getenv('E106B_USER'));
$session = clone app('session')->driver();
$session->setId(getenv('E106B_SESSION'));
$session->start();
$session->put('auth.version', $user->auth_version);
$request = Request::create('/team/invitations', 'POST');
$request->setLaravelSession($session);
$request->setUserResolver(fn () => $user);
$request->attributes->set('tenant.brand_revision', getenv('E106B_REVISION'));
$request->headers->set('X-Brand-Revision', getenv('E106B_HEADER'));
Auth::setUser($user);
$context = new TenantContext(getenv('E106B_ORG'), getenv('E106B_BRAND'), $user->id, getenv('E106B_MEMBERSHIP'));
$data = ['attempt_key' => getenv('E106B_ATTEMPT'), 'email' => 'race-invitee@example.test', 'role' => 'viewer',
    'brand_ids' => [getenv('E106B_BRAND')], 'sales_manager' => false];
echo "ready\n";
flush();
try {
    app(TenantContextRunner::class)->run($context, fn () => app(InvitationIssuer::class)->issue($request, $data));
    echo 'unexpected success';
    exit(1);
} catch (HttpException $exception) {
    echo 'denied:'.$exception->getStatusCode();
}
