<?php

use App\Tenancy\Cabinet;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$mode = $argv[1] ?? '';
$orgId = getenv('E107_FIXTURE_ORG');
if (getenv('E107_FIXTURE_ENABLED') !== '1' || ! app()->environment('testing') || realpath(base_path()) !== realpath(__DIR__.'/../../..') || basename(dirname(base_path())) !== '.worktrees' || basename(base_path()) !== 'sp-e1-07' || config('database.default') !== 'pgsql' || config('database.connections.pgsql.host') !== '127.0.0.1' || (string) config('database.connections.pgsql.port') !== '55607' || DB::connection()->getDatabaseName() !== 'souzpir_test_sp_e1_07' || config('runtime.test_database') !== 'souzpir_test_sp_e1_07' || config('runtime.preview_database') === 'souzpir_test_sp_e1_07' || $orgId !== '01J00000000000000000000000' || $orgId !== app(Cabinet::class)->organizationId() || ! in_array($mode, ['transfer', 'restore'], true)) {
    throw new LogicException('Requires the isolated SP-E1-07 fixture.');
}
$connection = DB::selectOne('select current_database() as db, current_user as role, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls from pg_roles where rolname = current_user');
if ($connection->db !== 'souzpir_test_sp_e1_07' || $connection->role !== 'souzpir_sp_e1_07' || $connection->rolsuper || $connection->rolcreatedb || $connection->rolcreaterole || $connection->rolreplication || $connection->rolbypassrls || config('features.E1-12.defaults.mail_enabled') !== false || config('features.E1-12.defaults.smtp_enabled') !== false || config('features.E1-12.defaults.recipient') !== null || config('features.E0-04.defaults.external_delivery') !== false || config('mail.default') !== 'log') {
    throw new LogicException('Unsafe database role or delivery configuration.');
}
DB::transaction(function () use ($orgId, $mode): void {
    $organization = DB::table('organizations')->where('id', $orgId)->lockForUpdate()->sole();
    $settings = json_decode($organization->settings, true, flags: JSON_THROW_ON_ERROR);
    if (($settings['test_fixture'] ?? null) !== 'SP-E1-07-browser-fixture-v1') {
        throw new LogicException('Not the marked fixture organization.');
    }
    $owner = DB::table('users')->where('email', 'billing-e107-owner@example.test')->where('name', 'Тестовый владелец')->sole();
    $admin = DB::table('users')->where('email', 'billing-e107-admin@example.test')->where('name', 'Тестовый администратор')->sole();
    $old = DB::table('memberships')->where('organization_id', $orgId)->where('user_id', $owner->id)->lockForUpdate()->sole();
    $new = DB::table('memberships')->where('organization_id', $orgId)->where('user_id', $admin->id)->lockForUpdate()->sole();
    $expected = $mode === 'transfer' ? [$owner->id, 'owner', 'admin'] : [$admin->id, 'admin', 'owner'];
    if ([$organization->owner_user_id, $old->role, $new->role] !== $expected || $old->status !== 'active' || $new->status !== 'active') {
        throw new LogicException('Unexpected fixture ownership state.');
    }
    DB::table('organizations')->where('id', $orgId)->update(['owner_user_id' => $mode === 'transfer' ? $admin->id : $owner->id]);
    DB::table('memberships')->where('id', $old->id)->update(['role' => $mode === 'transfer' ? 'admin' : 'owner']);
    DB::table('memberships')->where('id', $new->id)->update(['role' => $mode === 'transfer' ? 'owner' : 'admin']);
});
fwrite(STDOUT, "Fixture ownership changed.\n");
