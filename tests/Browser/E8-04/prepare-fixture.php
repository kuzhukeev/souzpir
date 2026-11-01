<?php

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$root = realpath(__DIR__.'/../../..');
$data = realpath($root.'/.local/postgres-e804-list');
$db = DB::connection();
if (getenv('E804_BROWSER_FIXTURE') !== '1' || ! $app->environment('testing') || realpath(getcwd()) !== $root
    || realpath($app->basePath()) !== $root || realpath(Application::inferBasePath()) !== $root || ! $data
    || $db->getDriverName() !== 'pgsql' || $db->getConfig('host') !== '127.0.0.1' || (string) $db->getConfig('port') !== '55810'
    || $db->getDatabaseName() !== 'souzpir_test_e804_list' || $db->getConfig('username') !== 'e804_list_owner'
    || config('runtime.test_database') !== 'souzpir_test_e804_list' || config('runtime.preview_database') === $db->getDatabaseName()) {
    throw new LogicException('Refusing a non-isolated E8-04 fixture.');
}
$admin = new PDO('pgsql:host=127.0.0.1;port=55810;dbname=postgres', 'e804_list_admin', '', [PDO::ATTR_TIMEOUT => 5]);
if (realpath($admin->query('SHOW data_directory')->fetchColumn()) !== $data) {
    throw new LogicException('Unexpected test cluster.');
}
$identity = $db->selectOne('select current_database() as db, current_user as role, host(inet_server_addr()) as host, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls from pg_roles where rolname=current_user');
if ($identity->db !== 'souzpir_test_e804_list' || $identity->role !== 'e804_list_owner' || $identity->host !== '127.0.0.1'
    || (int) $identity->port !== 55810 || $identity->rolsuper || $identity->rolcreatedb || $identity->rolcreaterole || $identity->rolreplication || $identity->rolbypassrls) {
    throw new LogicException('Unexpected test database identity.');
}
Http::preventStrayRequests();
Artisan::call('migrate', ['--force' => true]);
DB::transaction(function (): void {
    $id = '01J00000000000000000000000';
    if (Organization::whereKey($id)->exists() || User::where('email', 'e804-owner@example.test')->exists()) {
        throw new LogicException('Fixture collision.');
    }
    $owner = User::create(['name' => 'Тестовый владелец', 'email' => 'e804-owner@example.test', 'password' => 'test-only-password-123']);
    $owner->forceFill(['email_verified_at' => now()])->save();
    $org = new Organization;
    $org->forceFill(['id' => $id, 'name' => 'Тестовая организация', 'owner_user_id' => $owner->id])->save();
    app(TenantContextRunner::class)->run(new TenantContext($id, actorId: $owner->id, source: 'job'), function () use ($owner, $id): void {
        $first = Brand::create(['name' => 'Альфа', 'theme' => 'pyro']);
        $second = Brand::create(['name' => 'Бета', 'theme' => 'neutral']);
        Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
        foreach ([$first, $second] as $brand) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $set = (string) Str::ulid();
            DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $id, 'brand_id' => $brand->id, 'version' => 1, 'questions' => json_encode(['Тестовый вопрос']), 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('measurements')->insert(['id' => $brand === $first ? '01J00000000000000000000011' : '01J00000000000000000000022', 'organization_id' => $id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => $brand === $first ? 'running' : 'partial', 'configuration' => '{}', 'created_at' => $brand === $first ? '2026-10-10 12:30:00+00' : '2026-10-20 12:30:00+00', 'updated_at' => now()]);
            if ($brand === $first) {
                DB::table('measurements')->insert(['id' => '01J00000000000000000000033', 'organization_id' => $id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'created_at' => '2026-10-11 12:30:00+00', 'updated_at' => now()]);
                for ($index = 0; $index < 24; $index++) {
                    DB::table('measurements')->insert(['id' => (string) Str::ulid(), 'organization_id' => $id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => 'partial', 'configuration' => '{}', 'created_at' => '2026-10-01 12:30:00+00', 'updated_at' => now()]);
                }
            }
        }
    });
});
fwrite(STDOUT, "Isolated measurement list fixture ready; no run executed.\n");
