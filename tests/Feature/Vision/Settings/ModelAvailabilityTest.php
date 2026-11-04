<?php

namespace Tests\Feature\Vision\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ModelAvailabilityTest extends TenancyTestCase
{
    public function test_settings_show_only_catalog_planned_models_without_an_approval(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        $response = $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/settings')->assertOk();
        $options = collect($response->inertiaProps('modelOptions'));
        $this->assertNotEmpty($options);
        $this->assertTrue($options->every(fn (array $option): bool => $option['status'] === 'planned' && $option['approved'] === false));

        $set = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'version' => 1, 'questions' => '["Вопрос"]', 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vision_question_approvals')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'approved_by' => $owner->id, 'providers' => json_encode(['gemini' => 'google/gemini-2.5-flash']), 'active' => true, 'created_at' => now()]);
        $active = collect($this->get('/vision/settings')->assertOk()->inertiaProps('modelOptions'))
            ->firstWhere('id', 'google/gemini-2.5-flash');
        $this->assertSame('active', $active['status']);
        $this->assertTrue($active['approved']);

        $this->post('/logout')->assertRedirect();
        $this->get('/vision/settings')->assertRedirect();
    }
}
