<?php

namespace Tests\Feature\Vision\Clarifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ClarificationPaginationE816ITest extends TenancyTestCase
{
    public function test_confirmed_measurements_are_paginated_and_scoped(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $set = (string) Str::ulid();
            DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $item->id, 'version' => 1, 'questions' => json_encode([['id' => 'q1', 'text' => 'Вопрос']]), 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
            for ($i = 0; $i < ($item->id === $brand->id ? 51 : 1); $i++) {
                $measurement = (string) Str::ulid();
                DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org->id, 'brand_id' => $item->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'profile_snapshot' => '{}', 'profile_checksum' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('snapshot_confirmations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'measurement_id' => $measurement, 'confirmed_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $this->get('/vision/clarifications')->assertRedirect();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/clarifications')->assertOk()->assertInertia(fn ($page) => $page->has('measurements', 50)->where('page', 1)->where('hasMore', true));
        $this->get('/vision/clarifications?page=2')->assertOk()->assertInertia(fn ($page) => $page->has('measurements', 1)->where('page', 2)->where('hasMore', false));
        $this->get('/vision/clarifications?page=0')->assertSessionHasErrors('page');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/vision/clarifications')->assertOk()->assertInertia(fn ($page) => $page->has('measurements', 1)->where('hasMore', false));
    }
}
