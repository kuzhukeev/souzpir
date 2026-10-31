<?php

use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Onboarding\Http\OnboardingController;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[, $org, $user, $member, $name] = $argv;
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== config('runtime.test_database') || DB::connection()->getDatabaseName() === config('runtime.preview_database')) {
    throw new LogicException('Test database required.');
}
$pid = DB::selectOne('select pg_backend_pid() as pid')->pid;
echo 'ready:'.$pid."\n";
flush();
$data = ['organization_name' => 'Synthetic company', 'industry' => '', 'region' => '', 'timezone' => 'Europe/Moscow', 'website' => '', 'brand_name' => $name, 'alias' => '', 'theme' => 'neutral', 'seller' => true, 'vision' => false, 'step' => 4];
$request = Request::create('/start', 'POST', $data);
$session = app('session')->driver('array');
$session->start();
$request->setLaravelSession($session);
// Exercise the same server-side authorization lease used by real sessions.
$actor = User::findOrFail($user);
$session->put('auth.version', $actor->auth_version);
$request->setUserResolver(fn () => $actor);
app(AuthSessionRegistry::class)->issue($request, $actor, $actor->auth_version);
try {
    $context = new TenantContext($org, null, $user, $member, 'job');
    app(TenantContextRunner::class)->run($context, fn () => app(OnboardingController::class)->finish($request));
    echo $session->has('brand_id') ? 'created' : 'replay';
} catch (ValidationException $error) {
    if (! isset($error->errors()['onboarding'])) {
        throw $error;
    }
    echo 'conflict';
}
