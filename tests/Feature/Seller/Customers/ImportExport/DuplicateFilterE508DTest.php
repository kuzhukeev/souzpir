<?php

namespace Tests\Feature\Seller\Customers\ImportExport;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DuplicateFilterE508DTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function contact(string $org, string $brand, array $attrs): string
    {
        $id = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $id, 'organization_id' => $org, 'brand_id' => $brand, 'created_at' => now(), 'updated_at' => now(), ...$attrs]);

        return $id;
    }

    public function test_pairs_cover_every_match_reason_for_current_brand_only(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);

        $this->contact($org->id, $brand->id, ['name' => 'Алексей Смирнов', 'phone' => '+79991234567']);
        $this->contact($org->id, $brand->id, ['name' => 'А. Смирнов', 'phone' => '+79991234567']);
        $this->contact($org->id, $brand->id, ['name' => 'Ольга Соколова', 'email' => 'olga@example.test']);
        $this->contact($org->id, $brand->id, ['name' => 'Ольга С.', 'email' => 'olga@example.test']);
        $this->contact($org->id, $brand->id, ['name' => 'Иван Петров', 'city' => 'Тверь']);
        $this->contact($org->id, $brand->id, ['name' => 'Иван Петров', 'city' => 'Тверь']);
        $this->contact($org->id, $other->id, ['name' => 'Чужой', 'phone' => '+79991234567']);
        $this->contact($org->id, $other->id, ['name' => 'Чужой 2', 'phone' => '+79991234567']);

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $response = $this->get('/seller/customer-duplicates')->assertOk();
        $response->assertInertia(function ($page) {
            $page->has('pairs', 3);
            $reasons = collect($page->toArray()['props']['pairs'])->pluck('reason')->sort()->values()->all();
            $this->assertSame(['Имя и город', 'Почта', 'Телефон'], $reasons);
        });

        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/customer-duplicates')->assertForbidden();
    }
}
