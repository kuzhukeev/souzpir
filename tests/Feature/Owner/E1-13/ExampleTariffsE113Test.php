<?php

namespace Tests\Feature\Owner\E113;

use App\Shell\Plans\LimitEnforcer;
use App\Shell\Plans\MonthlyUnits;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\MeasurementRunner;
use App\Vision\Core\QuestionSets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ExampleTariffsE113Test extends TenancyTestCase
{
    public function test_exhausted_limit_stops_new_measurement_before_it_is_created(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        $plan = DB::table('plans')->where('code', 'start')->value('id');
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('plan_limits')->where('plan_id', $plan)->where('key', 'ai.units_month')->update(['value' => 0]);
        config(['features.E8-01.defaults.parser' => ['model' => 'google/gemini-2.5-flash', 'temperature' => 0.0, 'max_tokens' => 1200]]);
        $context = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($context, function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Как выбрать?', 'intent' => 'choose']]);
            try {
                app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
                self::fail('An exhausted limit must block a new measurement.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('measurement', $exception->errors());
            }
        });
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_example_limits_and_usage_apply_per_organization(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        [, $foreignOrg, $foreignBrand] = $this->cabinet(false);
        $plan = DB::table('plans')->where('code', 'start')->value('id');
        self::assertNotNull($plan);
        $this->assertDatabaseHas('plan_limits', ['plan_id' => $plan, 'key' => 'vision.questions', 'value' => 20]);
        $this->assertDatabaseHas('plan_limits', ['plan_id' => $plan, 'key' => 'vision.models', 'value' => 3]);
        $business = DB::table('plans')->where('code', 'business')->value('id');
        $this->assertDatabaseHas('plan_limits', ['plan_id' => $business, 'key' => 'ai.units_month', 'value' => 2800]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[$org, $brand], [$org, $other], [$foreignOrg, $foreignBrand]] as [$organization, $item]) {
            DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'brand_id' => $item->id, 'state' => '{}', 'mode' => 'bot', 'created_at' => now(), 'updated_at' => now()]);
            $set = (string) Str::ulid();
            $measurement = (string) Str::ulid();
            DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $organization->id, 'brand_id' => $item->id, 'version' => 1, 'questions' => '[{}]', 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $organization->id, 'brand_id' => $item->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('measurement_answers')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'brand_id' => $item->id, 'measurement_id' => $measurement, 'question_n' => 1, 'provider' => 'fake', 'requested_model' => 'fake', 'run_no' => 1, 'status' => 'ok', 'created_at' => now(), 'updated_at' => now()]);
        }
        $context = new TenantContext($org->id, $brand->id, $owner->id);
        self::assertSame(['dialogs' => 2, 'slots' => 2, 'units' => 4], app(MonthlyUnits::class)->usage($context));
        self::assertFalse(app(LimitEnforcer::class)->exhausted($context, 'ai.units_month', 799));
        self::assertTrue(app(LimitEnforcer::class)->exhausted($context, 'ai.units_month', 800));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/billing')->assertOk()->assertInertia(fn ($page) => $page->where('currentPlan.name', 'Старт')->where('examplePlans.start.price_rub', 9900)->where('examplePlans.business.price_rub', 24900));
        DB::table('organization_plan')->where('organization_id', $org->id)->update(['ends_at' => now()->subSecond()]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'plan_id' => $business, 'starts_at' => now()->subSecond(), 'created_at' => now(), 'updated_at' => now()]);
        self::assertSame(4, app(MonthlyUnits::class)->usage($context)['units']);
        self::assertFalse(app(LimitEnforcer::class)->exhausted($context, 'ai.units_month', 800));
        config(['features.E8-01.defaults.parser' => ['model' => 'google/gemini-2.5-flash', 'temperature' => 0.0, 'max_tokens' => 1200]]);
        app(TenantContextRunner::class)->run(new TenantContext($org->id, $brand->id, $owner->id, $member->id), function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q2', 'text' => 'Какая марка?', 'intent' => 'choose']]);
            try {
                app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
                self::fail('A completed measurement must exhaust the weekly limit.');
            } catch (ValidationException $exception) {
                self::assertStringContainsString('Недельный', $exception->errors()['measurement'][0]);
            }
        });
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/billing/plans')->assertForbidden();
    }

    public function test_bad_usage_is_rejected_and_unconfigured_limit_does_not_block(): void
    {
        [, $org, $brand] = $this->cabinet();
        $context = new TenantContext($org->id, $brand->id);
        self::assertFalse(app(LimitEnforcer::class)->exhausted($context, 'ai.units_month', 800));
        $this->expectException(\InvalidArgumentException::class);
        app(LimitEnforcer::class)->exhausted($context, 'ai.units_month', -1);
    }
}
