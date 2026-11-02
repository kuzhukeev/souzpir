<?php

namespace Tests\Feature\Vision\Sources;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class EmptySourcesTest extends TenancyTestCase
{
    public function test_real_reader_returns_empty_when_no_confirmed_snapshot_exists(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/sources')->assertOk()->assertInertia(fn ($page) => $page->where('available', false)->has('sources', 0));
        $this->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->where('available', false)->has('entities', 0));
    }
}
