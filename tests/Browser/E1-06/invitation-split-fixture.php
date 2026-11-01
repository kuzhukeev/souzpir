<?php

use App\Models\Brand;
use App\Models\Membership;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;

// Existing helper verifies the worktree, loopback cluster, DB identity,
// non-privileged role and marked synthetic organization before any writes.
require __DIR__.'/prepare-fixture.php';
$mode = $argv[1] ?? '';
if (! in_array($mode, ['setup', 'revoke', 'restore', 'count'], true)) {
    throw new LogicException('Unknown split-permission fixture action.');
}
$org = '01J00000000000000000000000';
$email = 'team-e106-admin@example.test';
if ($mode === 'setup') {
    $existing = User::where('email', $email)->first();
    if ($existing) {
        $membership = Membership::withoutGlobalScopes()->where('organization_id', $org)->where('user_id', $existing->id)->first();
        if (! $membership || $membership->role !== 'admin' || $membership->status !== 'active') {
            throw new LogicException('Unexpected split-permission fixture user.');
        }
    } else {
        DB::transaction(function () use ($org, $email): void {
            $owner = User::where('email', 'team-e106-owner@example.test')->firstOrFail();
            $brand = Brand::withoutGlobalScopes()->where('organization_id', $org)->where('name', 'Пиротехника — Северные огни')->firstOrFail();
            $admin = User::create(['name' => 'Тестовый администратор', 'email' => $email, 'password' => 'test-only-password-123']);
            $admin->forceFill(['email_verified_at' => now()])->save();
            app(TenantContextRunner::class)->run(new TenantContext($org, actorId: $owner->id, source: 'job'), fn () => Membership::create(['user_id' => $admin->id, 'role' => 'admin', 'brand_ids' => [$brand->id]]));
        });
    }
    echo "Split fixture ready.\n";
    exit;
}
$user = User::where('email', $email)->firstOrFail();
if ($mode === 'count') {
    $target = $argv[2] ?? '';
    if (! is_string($target) || ! preg_match('/^e106-split-[a-z0-9-]+@example\.test$/D', $target)) {
        throw new LogicException('Invalid synthetic invitation address.');
    }
    echo json_encode(['count' => DB::table('invitations')->where('organization_id', $org)->where('invited_by', $user->id)->where('email', $target)->count()], JSON_THROW_ON_ERROR)."\n";
    exit;
}
DB::transaction(function () use ($org, $user, $mode): void {
    $member = Membership::withoutGlobalScopes()->where('organization_id', $org)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
    $expected = $mode === 'revoke' ? 'admin' : 'viewer';
    if ($member->status !== 'active' || $member->role !== $expected) {
        throw new LogicException('Unexpected split-permission membership state.');
    }
    DB::table('memberships')->where('id', $member->id)->update(['role' => $mode === 'revoke' ? 'viewer' : 'admin']);
});
echo "Split fixture access changed.\n";
