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
$db = DB::connection()->getDatabaseName();
$orgId = getenv('E104_FIXTURE_ORG');
if (getenv('E104_FIXTURE_ENABLED') !== '1' || ! app()->environment('testing') || $db !== config('runtime.test_database') || $db === config('runtime.preview_database') || ! str_contains($db, '_test_') || ! is_string($orgId) || ! Str::isUlid($orgId) || $orgId !== app(Cabinet::class)->organizationId()) {
    throw new LogicException('Refusing fixture outside explicitly enabled isolated testing environment.');
}
Artisan::call('migrate', ['--force' => true]);
DB::transaction(function () use ($orgId): void {
    $marker = 'SP-E1-04-browser-fixture-v1';
    $owner = User::where('email', 'organizations-e104-owner@example.test')->lockForUpdate()->first();
    $member = User::where('email', 'organizations-e104-member@example.test')->lockForUpdate()->first();
    if (($owner && $owner->name !== $marker.' owner') || ($member && $member->name !== $marker.' member')) {
        throw new LogicException('Fixture user collision.');
    }
    $org = Organization::whereKey($orgId)->lockForUpdate()->first();
    if ($org) {
        if (($org->settings['test_fixture'] ?? null) !== $marker || ! $owner || ! $member || ! in_array($org->owner_user_id, [$owner->id, $member->id], true)) {
            throw new LogicException('Fixture organization collision.');
        }
        if (DB::table('memberships')->where('organization_id', $orgId)->whereNotIn('user_id', [$owner->id, $member->id])->exists() || DB::table('memberships')->whereIn('user_id', [$owner->id, $member->id])->where('organization_id', '!=', $orgId)->exists() || DB::table('audit_logs')->where('organization_id', $orgId)->whereNotNull('actor_id')->whereNotIn('actor_id', [$owner->id, $member->id])->exists()) {
            throw new LogicException('Fixture contains non-synthetic records.');
        }
        DB::table('audit_logs')->where('organization_id', $orgId)->delete();
        DB::table('brand_modules')->where('organization_id', $orgId)->delete();
        DB::table('brands')->where('organization_id', $orgId)->delete();
        DB::table('memberships')->where('organization_id', $orgId)->delete();
        DB::table('organizations')->where('id', $orgId)->delete();
    }
    $owner ??= User::create(['name' => $marker.' owner', 'email' => 'organizations-e104-owner@example.test', 'password' => 'test-only-password-123']);
    $member ??= User::create(['name' => $marker.' member', 'email' => 'organizations-e104-member@example.test', 'password' => 'test-only-password-123']);
    $owner->forceFill(['password' => 'test-only-password-123', 'email_verified_at' => now()])->save();
    $member->forceFill(['password' => 'test-only-password-123', 'email_verified_at' => now()])->save();
    $org = new Organization;
    $org->forceFill(['id' => $orgId, 'name' => 'Тестовая компания', 'settings' => ['test_fixture' => $marker]])->save();
    app(TenantContextRunner::class)->run(new TenantContext($orgId, actorId: $owner->id, source: 'job'), function () use ($owner, $member): void {
        Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
        Membership::create(['user_id' => $member->id, 'role' => 'member']);
        Brand::create(['name' => 'Первый бренд', 'theme' => 'neutral']);
    });
    $org->forceFill(['owner_user_id' => $owner->id])->save();
});
fwrite(STDOUT, "Isolated synthetic organizations fixture ready.\n");
