<?php

namespace Tests\Feature\SpUi04;

use App\Models\Brand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SellerCursorPaginationTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
    }

    public function test_lists_paginate_in_stable_order_without_crossing_brand_boundaries(): void
    {
        [, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        foreach (['conversations' => ['read', '/api/v1/seller/conversations'], 'contacts' => ['leads', '/api/v1/seller/leads']] as $table => [$ability, $url]) {
            $expected = [];
            for ($i = 0; $i < 53; $i++) {
                $expected[] = $this->record($table, $org->id, $brand->id);
            }
            $foreign = $this->record($table, $org->id, $other->id);
            rsort($expected);
            array_unshift($expected, $this->record($table, $org->id, $brand->id, '2026-11-02 12:00:00'));
            $token = $this->token($org->id, $brand, [$ability]);
            $first = $this->withHeader('Authorization', 'Bearer '.$token)->getJson($url)->assertOk()->assertJsonCount(50, 'data');
            self::assertSame(array_slice($expected, 0, 50), array_column($first->json('data'), 'id'));
            $cursor = $first->json('next_cursor');
            self::assertIsString($cursor);
            $second = $this->getJson($url.'?cursor='.urlencode($cursor))->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('next_cursor', null);
            self::assertSame(array_slice($expected, 50), array_column($second->json('data'), 'id'));
            self::assertNotContains($foreign, array_column($first->json('data'), 'id'));
            self::assertSame(['id', $table === 'contacts' ? 'name' : 'mode', 'created_at'], array_keys($first->json('data.0')));
            $otherToken = $this->token($org->id, $other, [$ability]);
            $this->withHeader('Authorization', 'Bearer '.$otherToken)->getJson($url.'?cursor='.urlencode($cursor))->assertUnprocessable()->assertJsonValidationErrors('cursor');
            $this->withHeader('Authorization', 'Bearer '.$token)->getJson($url.'?cursor=not-valid')->assertUnprocessable()->assertJsonValidationErrors('cursor');
            $this->getJson($url.'?cursor%5B%5D=x')->assertUnprocessable()->assertJsonValidationErrors('cursor');
        }
    }

    public function test_authorization_still_applies(): void
    {
        [, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->getJson('/api/v1/seller/conversations')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$this->token($org->id, $brand, ['read']))->getJson('/api/v1/seller/leads')->assertForbidden();
    }

    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function record(string $table, string $org, string $brand, string $created = '2026-11-01 12:00:00'): string
    {
        $id = (string) Str::ulid();
        $row = ['id' => $id, 'organization_id' => $org, 'brand_id' => $brand, 'created_at' => $created, 'updated_at' => now()];
        $row += $table === 'contacts' ? ['name' => 'Клиент', 'external_ids' => '{}'] : ['mode' => 'bot', 'state' => '{}'];
        DB::table($table)->insert($row);

        return $id;
    }

    private function token(string $org, Brand $brand, array $abilities): string
    {
        $secret = Str::random(64);
        $id = DB::table('personal_access_tokens')->insertGetId(['tokenable_type' => Brand::class, 'tokenable_id' => $brand->id,
            'organization_id' => $org, 'brand_id' => $brand->id, 'name' => 'synthetic', 'token' => hash('sha256', $secret),
            'token_last_four' => substr($secret, -4), 'abilities' => json_encode($abilities, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

        return $id.'|'.$secret;
    }
}
