<?php

use App\Models\Brand;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/runtime-guard.php';

$organization = '01J00000000000000000000000';
$owner = User::where('email', 'owner-e110@example.test')->first();
$brands = Brand::withoutGlobalScopes()->where('organization_id', $organization)->orderBy('id')->get();
if (! $owner || $brands->count() !== 2 || DB::table('channel_accounts')->where('organization_id', $organization)->exists()) {
    throw new LogicException('Expected only the isolated synthetic browser fixture.');
}
DB::transaction(function () use ($organization, $brands): void {
    foreach ($brands as $brand) {
        DB::table('channel_accounts')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization, 'brand_id' => $brand->id,
            'type' => 'telegram', 'name' => 'Synthetic channel', 'status' => 'active',
            'created_at' => '2020-01-02 10:00:00+00', 'updated_at' => '2026-10-31 12:00:00+00',
        ]);
    }
});
echo "Synthetic connection-date fixture ready.\n";
