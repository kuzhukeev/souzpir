<?php

namespace Tests\Feature\Seller\Quality;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class QualityDialogueCountsTest extends TenancyTestCase
{
    public function test_dialogue_counts_are_distinct_and_isolated_by_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $first = (string) Str::ulid();
        $second = (string) Str::ulid();
        $foreign = (string) Str::ulid();
        foreach ([[$first, $brand], [$second, $brand], [$foreign, $other]] as [$id, $item]) {
            DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $item->id, 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([[$first, $brand, true], [$first, $brand, false], [$second, $brand, false], [$foreign, $other, true]] as [$conversation, $item, $problem]) {
            DB::table('seller_evaluations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'conversation_id' => $conversation, 'scores' => '{}', 'issues' => json_encode($problem ? [['type' => 'other']] : []), 'average' => 4, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/quality')->assertOk()
            ->assertInertia(fn ($page) => $page->has('days.0.dialogueIds', 2)->has('days.0.problemDialogueIds', 1)->where('days.0.problemDialogueIds.0', $first));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/quality')->assertForbidden();
    }
}
