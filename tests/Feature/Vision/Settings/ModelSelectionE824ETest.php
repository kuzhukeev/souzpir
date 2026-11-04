<?php

namespace Tests\Feature\Vision\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ModelSelectionE824ETest extends TenancyTestCase
{
    public function test_models_save_while_schedule_is_off_and_survive_schedule_update(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enableVision($org->id, $brand->id);
        $this->enableVision($org->id, $other->id);
        $this->approve($org->id, $brand->id, $owner->id);

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/settings')->assertOk()->inertiaProps('revision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $this->withHeaders($headers)->put('/vision/settings/models', [
            'models' => ['google/gemini-2.5-flash'],
        ])->assertRedirect('/vision/settings')->assertSessionHasNoErrors();
        $row = DB::table('monitoring_schedules')->where('brand_id', $brand->id)->sole();
        self::assertFalse($row->enabled);
        self::assertSame(['google/gemini-2.5-flash'], array_values(json_decode($row->models, true)));
        self::assertSame(['google/gemini-2.5-flash'], $this->get('/vision/settings')->assertOk()->inertiaProps('schedule')['models']);

        $this->withHeaders($headers)->put('/vision/runs/schedule', ['enabled' => false, 'models' => []])
            ->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame(['google/gemini-2.5-flash'], array_values(json_decode(DB::table('monitoring_schedules')->where('brand_id', $brand->id)->value('models'), true)));
        self::assertSame(0, DB::table('monitoring_schedules')->where('brand_id', $other->id)->count());
        Http::assertNothingSent();
    }

    public function test_models_require_permission_current_brand_and_approved_active_input(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enableVision($org->id, $brand->id);
        $this->enableVision($org->id, $other->id);
        $this->approve($org->id, $brand->id, $owner->id);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);

        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/settings')->assertOk()->inertiaProps('revision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $this->withHeaders($headers)->put('/vision/settings/models', ['models' => ['google/gemini-2.5-flash']])->assertForbidden();

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/settings')->assertOk()->inertiaProps('revision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $this->withHeaders($headers)->put('/vision/settings/models', ['models' => ['gigachat']])->assertSessionHasErrors('models');
        $this->withHeaders($headers)->put('/vision/settings/models', ['models' => ['google/gemini-2.5-flash', 'google/gemini-2.5-flash']])->assertSessionHasErrors('models.1');
        $this->withHeaders($headers)->put('/vision/settings/models', ['models' => []])->assertSessionHasErrors('models');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->withHeaders($headers)->put('/vision/settings/models', ['models' => ['google/gemini-2.5-flash']])->assertNotFound();
        self::assertSame(0, DB::table('monitoring_schedules')->count());
    }

    private function enableVision(string $organization, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization,
            'brand_id' => $brand, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function approve(string $organization, string $brand, string $owner): void
    {
        $set = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $organization, 'brand_id' => $brand,
            'version' => 1, 'questions' => json_encode(['Вопрос'], JSON_THROW_ON_ERROR), 'size' => 1,
            'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vision_question_approvals')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization,
            'brand_id' => $brand, 'question_set_id' => $set, 'approved_by' => $owner,
            'providers' => json_encode(['gemini' => 'google/gemini-2.5-flash', 'giga' => 'gigachat'], JSON_THROW_ON_ERROR),
            'active' => true, 'created_at' => now()]);
    }
}
