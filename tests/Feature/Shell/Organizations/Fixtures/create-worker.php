<?php

use App\Models\User;
use App\Shell\Organizations\Http\OrganizationController;
use App\Shell\Plans\LimitEnforcer;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[, $org, $brand, $user, $member, $name] = $argv;
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== config('runtime.test_database') || DB::connection()->getDatabaseName() === config('runtime.preview_database')) {
    throw new LogicException('Test database required.');
}
$actor = User::findOrFail($user);
Auth::setUser($actor);
echo 'ready:'.DB::selectOne('select pg_backend_pid() as pid')->pid."\n";
flush();
$request = Request::create('/brands', 'POST', ['name' => $name, 'theme' => 'neutral']);
try {
    $context = new TenantContext($org, $brand, $user, $member, 'job');
    app(TenantContextRunner::class)->run($context, fn () => app(OrganizationController::class)->createBrand($request, app(LimitEnforcer::class)));
    echo 'created';
} catch (ValidationException $error) {
    if (! isset($error->errors()['brands.max'])) {
        throw $error;
    }
    echo 'limited';
}
