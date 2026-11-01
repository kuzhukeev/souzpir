<?php

use App\Models\Brand;
use App\Models\Membership;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;

require __DIR__.'/runtime-guard.php';

$organization = '01J00000000000000000000000';
$brand = Brand::withoutGlobalScopes()->where('organization_id', $organization)->orderBy('id')->first();
if (! $brand || User::where('email', 'viewer-e110@example.test')->exists()) {
    throw new LogicException('Expected only the isolated synthetic browser fixture.');
}
DB::transaction(function () use ($organization, $brand): void {
    $viewer = User::create(['name' => 'Тестовый наблюдатель', 'email' => 'viewer-e110@example.test', 'password' => 'test-only-password-123']);
    $viewer->forceFill(['email_verified_at' => now()])->save();
    app(TenantContextRunner::class)->run(new TenantContext($organization, actorId: $viewer->id, source: 'job'), function () use ($viewer, $brand): void {
        Membership::create(['user_id' => $viewer->id, 'role' => 'viewer', 'brand_ids' => [$brand->id]]);
    });
});
echo "Synthetic viewer fixture ready.\n";
