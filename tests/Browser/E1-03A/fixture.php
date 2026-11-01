<?php

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$root = realpath(getcwd());
$data = realpath($root.'/.local/postgres/data');
$db = DB::connection('pgsql');
if (getenv('E103A_BROWSER_FIXTURE') !== '1' || ! $app->environment('testing')
    || realpath($app->basePath()) !== $root || realpath(Application::inferBasePath()) !== $root
    || ! $data || ! str_starts_with(strtolower($data), strtolower(realpath($root.'/.local').DIRECTORY_SEPARATOR))
    || $db->getConfig('host') !== '127.0.0.1' || (string) $db->getConfig('port') !== '55866'
    || $db->getDatabaseName() !== 'souzpir_test_sp_e1_03a' || $db->getConfig('username') !== 'sp_e103a_app'
    || config('runtime.test_database') !== 'souzpir_test_sp_e1_03a' || config('runtime.preview_database') === 'souzpir_test_sp_e1_03a'
    || app(Cabinet::class)->organizationId() !== '01J00000000000000000000000') {
    throw new LogicException('Dedicated HomeEntry test database required.');
}
$admin = new PDO('pgsql:host=127.0.0.1;port=55866;dbname=postgres', 'sp_e103a_admin', '', [PDO::ATTR_TIMEOUT => 5]);
if (realpath($admin->query('SHOW data_directory')->fetchColumn()) !== $data) {
    throw new LogicException('Unexpected HomeEntry database cluster.');
}
$identity = $db->selectOne('select current_database() as db, current_user as role, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls from pg_roles where rolname=current_user');
if ($identity->db !== 'souzpir_test_sp_e1_03a' || $identity->role !== 'sp_e103a_app'
    || $identity->rolsuper || $identity->rolcreatedb || $identity->rolcreaterole || $identity->rolreplication || $identity->rolbypassrls) {
    throw new LogicException('Unsafe HomeEntry database role.');
}
Artisan::call('migrate', ['--force' => true]);
$orgId = '01J00000000000000000000000';
$existing = Organization::whereKey($orgId)->first();
if ($existing) {
    if (($existing->settings['test_fixture'] ?? null) !== 'e103a-home') {
        throw new LogicException('Fixture organization collision.');
    }
    echo "HomeEntry fixture already exists.\n";
    exit;
}
if (User::whereIn('email', ['home-owner@example.test', 'home-viewer@example.test'])->exists()) {
    throw new LogicException('Fixture user collision.');
}
DB::transaction(function () use ($orgId): void {
    $owner = User::create(['name' => 'Тестовый владелец', 'email' => 'home-owner@example.test', 'password' => 'test-only-password-123']);
    $viewer = User::create(['name' => 'Тестовый наблюдатель', 'email' => 'home-viewer@example.test', 'password' => 'test-only-password-123']);
    $owner->forceFill(['email_verified_at' => now()])->save();
    $viewer->forceFill(['email_verified_at' => now()])->save();
    $organization = new Organization;
    $organization->forceFill(['id' => $orgId, 'name' => 'Тестовая компания', 'owner_user_id' => $owner->id, 'settings' => ['test_fixture' => 'e103a-home']])->save();
    app(TenantContextRunner::class)->run(new TenantContext($orgId, actorId: $owner->id, source: 'job'), function () use ($owner, $viewer): void {
        $first = Brand::create(['name' => 'Альфа — Пиротехника', 'theme' => 'pyro']);
        Brand::create(['name' => 'Бета — Кондитерская', 'theme' => 'confectionery']);
        Brand::create(['name' => 'Вектор — нейтральный бренд', 'theme' => 'neutral']);
        Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
        Membership::create(['user_id' => $viewer->id, 'role' => 'viewer', 'brand_ids' => [$first->id]]);
    });
});
echo "Synthetic HomeEntry fixture ready.\n";
