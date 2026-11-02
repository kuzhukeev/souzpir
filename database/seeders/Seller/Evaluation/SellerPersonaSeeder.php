<?php

namespace Database\Seeders\Seller\Evaluation;

use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

final class SellerPersonaSeeder extends Seeder
{
    public function seedForBrand(TenantContext $context): int
    {
        $organization = $context->requireOrganizationId();
        $brand = $context->brandId ?? throw new RuntimeException('Brand is required.');
        $exists = DB::table('brands')->where('id', $brand)->where('organization_id', $organization)->exists();
        if (! $exists) {
            throw new RuntimeException('Brand not found.');
        }
        $personas = Yaml::parseFile(__DIR__.'/personas.yaml');
        if (! is_array($personas) || count($personas) !== 29 || count(array_unique(array_column($personas, 'id'))) !== 29) {
            throw new RuntimeException('Invalid persona fixture.');
        }
        foreach ($personas as $persona) {
            DB::table('seller_personas')->insertOrIgnore(
                ['id' => (string) Str::ulid(), 'organization_id' => $organization, 'brand_id' => $brand,
                    'key' => $persona['id'], 'name' => $persona['name'], 'type' => $persona['type'] ?? 'edge',
                    'payload' => json_encode($persona, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'max_turns' => $persona['max_turns'] ?? 12, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        return count($personas);
    }
}
