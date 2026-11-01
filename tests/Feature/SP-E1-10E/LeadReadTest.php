<?php

namespace Tests\Feature\SpE110E;

use App\Models\Brand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class LeadReadTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
    }

    public function test_read_key_sees_only_its_brand_and_no_private_payload(): void
    {
        [, $org, $first, $second] = $this->cabinet();
        $this->enable($org->id, $first->id);
        $one = $this->lead($org->id, $first->id);
        $two = $this->lead($org->id, $second->id);
        $key = $this->token($org->id, $first, ['leads']);
        $this->withHeader('Authorization', 'Bearer '.$key)->getJson('/api/v1/seller/leads')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $one);
        $this->getJson('/api/v1/seller/leads/'.$one)->assertOk()->assertJsonPath('data.id', $one)->assertJsonMissingPath('data.external_ids');
        $this->getJson('/api/v1/seller/leads/'.$two)->assertNotFound();
    }

    public function test_missing_or_wrong_ability_key_is_rejected(): void
    {
        [, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->getJson('/api/v1/seller/leads')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$this->token($org->id, $brand, ['read']))
            ->getJson('/api/v1/seller/leads')->assertForbidden();
    }

    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function lead(string $org, string $brand): string
    {
        $id = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $id, 'organization_id' => $org, 'brand_id' => $brand, 'name' => 'Клиент', 'external_ids' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function token(string $org, Brand $brand, array $abilities): string
    {
        $secret = Str::random(64);
        $id = DB::table('personal_access_tokens')->insertGetId(['tokenable_type' => Brand::class, 'tokenable_id' => $brand->id,
            'organization_id' => $org, 'brand_id' => $brand->id, 'name' => 'synthetic', 'token' => hash('sha256', $secret),
            'token_last_four' => substr($secret, -4), 'abilities' => json_encode($abilities, JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now()]);

        return $id.'|'.$secret;
    }
}
