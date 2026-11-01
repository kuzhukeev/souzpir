<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$root = realpath(__DIR__.'/../../..');
$data = realpath($root.'/.local/postgres/data');
$db = DB::connection();
$identity = $db->selectOne('select current_database() as db, current_user as role, host(inet_server_addr()) as address, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls from pg_roles where rolname=current_user');
if (getenv('E005E_BROWSER_FIXTURE') !== '1' || ! $app->environment('testing') || realpath(getcwd()) !== $root
    || realpath($app->basePath()) !== $root || realpath(Application::inferBasePath()) !== $root || ! $data
    || $db->getDriverName() !== 'pgsql' || $db->getConfig('host') !== '127.0.0.1' || (string) $db->getConfig('port') !== '55863'
    || $identity->db !== 'souzpir_test_sp_e0_05e' || $identity->role !== 'sp_e005e_app' || $identity->address !== '127.0.0.1' || (string) $identity->port !== '55863'
    || $identity->rolsuper || $identity->rolcreatedb || $identity->rolcreaterole || $identity->rolreplication || $identity->rolbypassrls
    || config('runtime.test_database') !== $identity->db || config('runtime.preview_database') === $identity->db) {
    throw new LogicException('Refusing a non-isolated AI recovery fixture.');
}
$admin = new PDO('pgsql:host=127.0.0.1;port=55863;dbname=postgres', 'sp_e005e_admin', '', [PDO::ATTR_TIMEOUT => 5]);
if (realpath($admin->query('SHOW data_directory')->fetchColumn()) !== $data) {
    throw new LogicException('Unexpected test cluster.');
}
$admin = null;
$org = Organization::whereKey('01J00000000000000000000000')->firstOrFail();
if (($org->settings['test_fixture'] ?? null) !== 'e106-role-ui') {
    throw new LogicException('Unexpected fixture organization.');
}
if (getenv('E005E_REVOKE_SESSION') === '1') {
    DB::table('users')->where('id', $org->owner_user_id)->increment('auth_version');
    fwrite(STDOUT, '{"revoked":true}');
    exit;
}
$first = Brand::withoutGlobalScopes()->where('organization_id', $org->id)->where('name', 'Пиротехника — Северные огни')->firstOrFail();
$second = Brand::withoutGlobalScopes()->where('organization_id', $org->id)->where('name', 'Фейерверки — Второй бренд')->firstOrFail();
$empty = Brand::withoutGlobalScopes()->where('organization_id', $org->id)->where('name', 'Пустой тестовый бренд')->first();
if (! $empty) {
    $empty = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $org->owner_user_id, source: 'job'), fn () => Brand::create(['name' => 'Пустой тестовый бренд', 'theme' => 'neutral']));
}
DB::transaction(function () use ($org, $first, $second): void {
    foreach ([[$first, 52], [$second, 1]] as [$brand, $count]) {
        $existing = DB::table('ai_calls')->where('organization_id', $org->id)->where('brand_id', $brand->id)->where('purpose', 'E005E_FIXTURE')->count();
        if ($existing === $count) {
            continue;
        }
        if ($existing !== 0) {
            throw new LogicException('Unexpected existing fixture calls.');
        }
        $budget = (string) Str::ulid();
        DB::table('ai_budgets')->insert(['id' => $budget, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'family' => 'seller', 'period' => '2031-01-01', 'created_at' => now(), 'updated_at' => now()]);
        for ($i = 0; $i < $count; $i++) {
            $id = (string) Str::ulid();
            DB::table('ai_calls')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'budget_id' => $budget, 'idempotency_key_hash' => hash('sha256', $id), 'request_hash' => hash('sha256', 'fixture-'.$id), 'family' => 'seller', 'purpose' => 'E005E_FIXTURE', 'provider' => 'PRIVATE_PROVIDER', 'requested_model' => 'PRIVATE_MODEL', 'model' => 'PRIVATE_MODEL', 'object_type' => 'brand', 'object_id' => $brand->id, 'data_classes' => '[]', 'attempt' => 1, 'status' => $i % 2 ? 'reserved' : 'uncertain', 'reserved_micros' => 123456789, 'result_encrypted' => 'PRIVATE_RESULT', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
});
$ids = DB::table('ai_calls')->where('organization_id', $org->id)->where('brand_id', $first->id)->where('purpose', 'E005E_FIXTURE')->orderBy('id')->pluck('id')->all();
$otherId = DB::table('ai_calls')->where('organization_id', $org->id)->where('brand_id', $second->id)->where('purpose', 'E005E_FIXTURE')->value('id');
fwrite(STDOUT, json_encode(['firstBrand' => $first->id, 'secondBrand' => $second->id, 'emptyBrand' => $empty->id, 'firstId' => $ids[0], 'secondPageId' => $ids[50], 'otherId' => $otherId], JSON_THROW_ON_ERROR));
