<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
$root = realpath(__DIR__.'/../../..');
$local = realpath($root.'/.local');
$data = realpath((string) getenv('SP_ROLE_TEST_DATA'));
$testHost = (string) getenv('SP_ROLE_TEST_HOST');
$port = getenv('SP_ROLE_TEST_PORT');
$admin = getenv('SP_ROLE_TEST_ADMIN');
$testDb = getenv('SP_ROLE_TEST_DB');
$runtime = getenv('SP_ROLE_TEST_RUNTIME');
if (! $app->environment('testing') || realpath($app->basePath()) !== $root
    || realpath(getcwd()) !== $root || $db->getDriverName() !== 'pgsql' || ! $local || ! $data
    || ! str_starts_with(strtolower($data), strtolower($local.DIRECTORY_SEPARATOR))
    || ! preg_match('/^souzpir_test_[a-z0-9_]+$/D', (string) $testDb)
    || ! preg_match('/^[a-z][a-z0-9_]{0,62}$/D', (string) $admin)
    || ! preg_match('/^[a-z][a-z0-9_]{0,62}$/D', (string) $runtime)
    || filter_var($testHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || ! str_starts_with($testHost, '127.')
    || ! ctype_digit((string) $port) || $db->getConfig('host') !== $testHost || (string) $db->getConfig('port') !== $port
    || $db->getDatabaseName() !== $testDb || $db->getConfig('username') !== $runtime
    || $testDb !== config('runtime.test_database') || $testDb === config('runtime.preview_database')) {
    throw new RuntimeException('Refusing non-isolated runtime application smoke test.');
}
$adminConnection = new PDO("pgsql:host={$testHost};port={$port};dbname=postgres", $admin, '', [PDO::ATTR_TIMEOUT => 5]);
if (realpath($adminConnection->query('SHOW data_directory')->fetchColumn()) !== $data) {
    throw new RuntimeException('PostgreSQL data directory is not the worktree-local test cluster.');
}
if ($adminConnection->query("SELECT host(inet_server_addr()) || '|' || inet_server_port()")->fetchColumn() !== $testHost.'|'.$port) {
    throw new RuntimeException('Unexpected PostgreSQL server address or port.');
}
$adminConnection = null;
$identity = $db->selectOne('select current_database() as database, current_user as role, host(inet_server_addr()) as address, inet_server_port() as port');
if ($identity->database !== $testDb || $identity->role !== $runtime || $identity->address !== $testHost || (string) $identity->port !== $port) {
    throw new RuntimeException('Unexpected runtime database identity.');
}
Http::preventStrayRequests();
Mail::fake();
Notification::fake();
Queue::fake();

// The transaction is always rolled back; no actual user or organization remains.
$email = uniqid('role-', true).'@example.test';
$db->beginTransaction();
try {
    $user = User::create(['name' => 'Test User', 'email' => $email, 'password' => 'test-only']);
    $org = Organization::create(['name' => 'Test Organization']);
    app(TenantContextRunner::class)->run(new TenantContext($org->id, source: 'job'), function () use ($user): void {
        $member = Membership::create(['user_id' => $user->id, 'role' => 'member']);
        $brand = Brand::create(['name' => 'Test Brand', 'theme' => 'neutral']);
        $member->update(['role' => 'viewer']);
        if ($brand->organization_id !== $member->organization_id || $member->fresh()->role !== 'viewer') {
            throw new RuntimeException('Runtime application DML did not persist within the transaction.');
        }
        $member->delete();
    });
    $user->update(['name' => 'Updated Test User']);
    if ($user->fresh()->name !== 'Updated Test User') {
        throw new RuntimeException('Runtime application update failed.');
    }
} finally {
    if ($db->transactionLevel() > 0) {
        $db->rollBack(0);
    }
}
if (User::where('email', $email)->exists()) {
    throw new RuntimeException('Test transaction did not roll back.');
}
echo "application_runtime_dml_passed\n";
