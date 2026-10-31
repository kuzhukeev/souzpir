<?php

use App\Models\User;
use App\Shell\Organizations\Http\OrganizationController;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[, $org, $brand, $user, $member, $target] = $argv;
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== config('runtime.test_database') || DB::connection()->getDatabaseName() === config('runtime.preview_database')) {
    throw new LogicException('Test database required.');
}
Auth::setUser(User::findOrFail($user));
echo 'ready:'.DB::selectOne('select pg_backend_pid() as pid')->pid."\n";
flush();
$request = Request::create('/company/transfer', 'POST', ['user_id' => $target, 'confirm_name' => 'E104 transfer fixture']);
try {
    $context = new TenantContext($org, $brand, $user, $member, 'job');
    app(TenantContextRunner::class)->run($context, fn () => app(OrganizationController::class)->transfer($request));
    echo 'transferred';
} catch (HttpException $error) {
    if ($error->getStatusCode() !== 403) {
        throw $error;
    }
    echo 'denied';
}
