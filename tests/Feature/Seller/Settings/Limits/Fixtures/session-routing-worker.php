<?php

use App\Models\User;
use App\Seller\Settings\Limits\DigestPreferenceController;
use App\Seller\Settings\Limits\NotificationsController;
use App\Seller\Settings\Limits\TestDeliveryController;
use App\Seller\Settings\Limits\TestDeliveryService;
use App\Shell\Auth\Http\PasswordController;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
\Tests\TestCase::assertIsolatedTestDatabase();
$data = json_decode(fgets(STDIN), true, 32, JSON_THROW_ON_ERROR);
$user = User::findOrFail($data['user']);
$session = app('session')->driver('array');
$session->setId($data['session']);
$session->start();
$session->put('auth.version', $data['version']);
$request = Request::create('/', 'POST', $data['body'] ?? []);
$request->setLaravelSession($session);
$request->setUserResolver(fn () => $user);
Auth::guard('web')->setUser($user);
Queue::fake();
config(['features.E6-07.defaults.digest_enabled' => true, 'features.E0-02.defaults.organization_id' => $data['organization']]);
echo 'ready:'.DB::selectOne('select pg_backend_pid() as pid')->pid."\n";
flush();
fgets(STDIN);
if ($data['action'] === 'password') {
    $request->merge(['email' => $user->email, 'token' => $data['token'], 'password' => 'synthetic-new-password', 'password_confirmation' => 'synthetic-new-password']);
    $response = app(PasswordController::class)->store($request);
    if ($response->getTargetUrl() !== route('login')) {
        throw new LogicException('Password reset failed.');
    }
    echo 'ok';
    exit;
}
$context = new TenantContext($data['organization'], $data['brand'], $user->id, $data['membership'], 'session');
try {
    app(TenantContextRunner::class)->run($context, function () use ($data, $request, $context): void {
        match ($data['action']) {
            'rules' => app(NotificationsController::class)->update($request, $context),
            'time' => app(DigestPreferenceController::class)($request, $context),
            'test' => app(TestDeliveryController::class)($request, $context, app(TestDeliveryService::class)),
            default => throw new LogicException('Unknown action.'),
        };
    });
} catch (AuthenticationException) {
    echo 'denied';
    exit;
}
echo 'ok';
