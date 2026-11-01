<?php

use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Measurements\MeasurementList;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Vision\Measurements\Fixtures\IsolatedDatabaseGuard;

$root = realpath(__DIR__.'/../../../../..');
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('E804_ACCESS_RACE') !== '1' || realpath(getcwd()) !== $root || realpath($app->basePath()) !== $root) {
    throw new LogicException('Refusing a race worker outside the isolated E8-04 database.');
}
IsolatedDatabaseGuard::check();
$org = (string) getenv('E804_ORG');
$brand = (string) getenv('E804_BRAND');
$plan = (string) getenv('E804_PLAN');
$kind = (string) getenv('E804_KIND');
if (! Str::isUlid($org) || ! Str::isUlid($brand) || ! Str::isUlid($plan)) {
    throw new LogicException('Invalid fixture identity.');
}
if ($kind === 'reader') {
    $user = User::findOrFail((string) getenv('E804_USER'));
    $request = Request::create('/vision/runs/data', 'GET');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->setId(Str::random(40));
    $request->session()->start();
    $request->session()->put('auth.version', $user->auth_version);
    $request->session()->put('brand_id', $brand);
    $request->setUserResolver(fn () => $user);
    app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
    $selection = app(AuthSessionRegistry::class)->withinActive($request, fn () => app(SessionBrandSelection::class)->initialize($request, $org, $brand));
    $request->attributes->set('tenant.brand_revision', (string) $selection->brand_revision);
    $context = new TenantContext($org, $brand, $user->id, (string) getenv('E804_MEMBER'));
    fwrite(STDOUT, "ready\n");
    flush();
    try {
        app(TenantContextRunner::class)->run($context, fn () => app(MeasurementList::class)->withinCurrent($request, fn () => null, false));
        fwrite(STDOUT, "allowed\n");
    } catch (HttpException $error) {
        fwrite(STDOUT, 'denied:'.$error->getStatusCode()."\n");
    }
    exit;
}
fwrite(STDOUT, "ready\n");
flush();
switch ($kind) {
    case 'module':
        DB::table('brand_modules')->where('organization_id', $org)->where('brand_id', $brand)->where('module', 'vision')->update(['enabled' => false]);
        break;
    case 'limit_update':
        DB::table('plan_limits')->where('plan_id', $plan)->where('key', 'modules.vision')->update(['value' => 0]);
        break;
    case 'limit_insert':
        DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => 'modules.vision', 'value' => 0, 'unit' => 'bool', 'created_at' => now(), 'updated_at' => now()]);
        break;
    case 'assignment_insert':
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        break;
    default:
        throw new LogicException('Unknown race mode.');
}
fwrite(STDOUT, "updated\n");
