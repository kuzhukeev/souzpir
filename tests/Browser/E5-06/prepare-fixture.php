<?php

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
$database = $connection->getDatabaseName();
$actual = $connection->selectOne('select current_database() as name')->name;
$orgId = getenv('E506_FIXTURE_ORG');
$base = realpath($app->basePath());
$cwd = realpath(getcwd());
if (getenv('E506_FIXTURE_ENABLED') !== '1' || ! $app->environment('testing')
    || $connection->getDriverName() !== 'pgsql' || ! preg_match('/(?:^|_)test(?:_|$)/', $database)
    || $database !== $actual || $database !== config('runtime.test_database') || $database === config('runtime.preview_database')
    || ! $base || ! $cwd || strcasecmp($base, $cwd) !== 0
    || ! is_string($orgId) || ! Str::isUlid($orgId) || $orgId !== app(Cabinet::class)->organizationId()) {
    throw new LogicException('Requires the explicitly enabled isolated testing database and fixture organization.');
}
Artisan::call('migrate', ['--force' => true]);
$fixture = DB::transaction(function () use ($orgId): array {
    $marker = 'SP-E5-06-browser-fixture-v1';
    $email = 'planned-e506-owner@example.test';
    $user = User::where('email', $email)->lockForUpdate()->first();
    if ($user && ($user->name !== 'Анна Лебедева' || DB::table('memberships')->where('user_id', $user->id)->where('organization_id', '!=', $orgId)->exists())) {
        throw new LogicException('Fixture user collision.');
    }
    $user ??= User::create(['name' => 'Анна Лебедева', 'email' => $email, 'password' => 'test-only-password-123']);
    $user->forceFill(['email_verified_at' => now(), 'terms_accepted_at' => now(), 'ai_consent_at' => now()])->save();
    $org = Organization::whereKey($orgId)->lockForUpdate()->first();
    if ($org && (($org->settings['test_fixture'] ?? null) !== $marker || $org->owner_user_id !== $user->id)) {
        throw new LogicException('Fixture organization collision.');
    }
    if (! $org) {
        $org = new Organization;
        $org->forceFill(['id' => $orgId, 'name' => 'Союз Пир — тест каналов', 'settings' => ['test_fixture' => $marker]])->save();
    }
    $brands = [];
    app(TenantContextRunner::class)->run(new TenantContext($orgId, actorId: $user->id, source: 'job'), function () use ($orgId, $user, $marker, &$brands): void {
        foreach (['neutral' => 'Союз Пир — основной бренд', 'pyro' => 'Праздничные огни и профессиональная пиротехника', 'confectionery' => 'Кондитерская «Малиновый сад»'] as $theme => $name) {
            $brand = Brand::withoutGlobalScopes()->where('organization_id', $orgId)->where('name', $name)->first();
            if ($brand && ($brand->settings['test_fixture'] ?? null) !== $marker) {
                throw new LogicException('Fixture brand collision.');
            }
            $brand ??= Brand::create(['name' => $name, 'theme' => $theme, 'status' => 'active', 'settings' => ['test_fixture' => $marker]]);
            $brands[$theme] = ['id' => $brand->id, 'name' => $brand->name];
            $module = DB::table('brand_modules')->where('organization_id', $orgId)->where('brand_id', $brand->id)->where('module', 'seller')->first();
            if (! $module) {
                DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $orgId,
                    'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            } elseif (! $module->enabled) {
                throw new LogicException('Fixture seller module is not enabled.');
            }
        }
        $member = Membership::withoutGlobalScopes()->where('organization_id', $orgId)->where('user_id', $user->id)->first();
        if (! $member) {
            Membership::create(['user_id' => $user->id, 'role' => 'owner', 'brand_ids' => []]);
        }
    });
    $org->forceFill(['owner_user_id' => $user->id])->save();

    return ['organization_id' => $orgId, 'brands' => $brands, 'owner_email' => $email];
});
file_put_contents(base_path('.local/runtime/e5-06-browser.json'), json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
fwrite(STDOUT, "Isolated channel fixture ready.\n");
