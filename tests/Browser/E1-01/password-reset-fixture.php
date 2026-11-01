<?php

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\PasswordDelivery\PasswordDelivery;
use App\Shell\Auth\PasswordDelivery\PasswordTransport;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedAuthDatabase;

putenv('APP_ENV=testing');
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
IsolatedAuthDatabase::assert();
$identity = DB::selectOne(<<<'SQL'
    SELECT current_database() db, current_user role, inet_server_addr() host, inet_server_port() port,
           r.rolsuper, r.rolcreatedb, r.rolcreaterole, r.rolreplication, r.rolbypassrls,
           d.datdba = r.oid owns_database
    FROM pg_roles r JOIN pg_database d ON d.datname = current_database() WHERE r.rolname = current_user
    SQL);
if (getenv('E101G_BROWSER_FIXTURE') !== '1' || realpath(base_path()) !== realpath(getcwd())
    || basename(base_path()) !== 'sp-e1-01g' || $identity->db !== 'souzpir_test_sp_e1_01g'
    || $identity->role !== 'sp_e101g_app' || $identity->role !== config('database.connections.pgsql.username') || $identity->host !== '127.0.0.1'
    || (int) $identity->port !== 55841 || (int) $identity->port !== (int) config('database.connections.pgsql.port') || ! $identity->owns_database
    || $identity->rolsuper || $identity->rolcreatedb || $identity->rolcreaterole || $identity->rolreplication || $identity->rolbypassrls) {
    throw new RuntimeException('Dedicated browser test database required.');
}
if (($argv[1] ?? '') !== 'setup') {
    throw new InvalidArgumentException('Unknown fixture action.');
}
$cachePath = realpath(config('cache.stores.file.path'));
if (config('cache.default') !== 'file' || $cachePath === false || $cachePath !== realpath(storage_path('framework/cache/data'))
    || ! str_starts_with(str_replace('\\', '/', storage_path()), str_replace('\\', '/', base_path('.local/runtime/testing')).'/')) {
    throw new RuntimeException('Dedicated browser cache required.');
}
Cache::flush();
Artisan::call('migrate:fresh', ['--force' => true]);
$org = new Organization;
$org->forceFill(['id' => app(Cabinet::class)->organizationId(), 'name' => 'Synthetic recovery'])->save();
$user = User::create(['name' => 'Synthetic User', 'email' => 'recovery-browser@example.test', 'password' => 'old-synthetic-password']);
app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id), fn () => Membership::create(['user_id' => $user->id, 'role' => 'owner']));
$org->forceFill(['owner_user_id' => $user->id])->save();
config(['features.E1-01F.defaults.enabled' => true]);
$transport = new class implements PasswordTransport
{
    public string $token = '';

    public function send(#[SensitiveParameter] User $user, #[SensitiveParameter] string $token): bool
    {
        $this->token = $token;

        return true;
    }
};
$delivery = app(PasswordDelivery::class);
$id = $delivery->enqueue($user->email);
$delivery->send($delivery->claim($id), $transport);
echo $transport->token;
