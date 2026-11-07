<?php

namespace Tests\Feature\Vision\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SlotHistoryE824FTest extends TenancyTestCase
{
    public function test_monthly_organization_balance_and_brand_history_are_scoped(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [, $foreignOrg, $foreign] = $this->cabinet(false);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $plan = DB::table('plans')->where('code', 'start')->value('id');
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $ids = [];
        foreach ([[$org, $brand], [$org, $other], [$foreignOrg, $foreign]] as [$organization, $item]) {
            $set = (string) Str::ulid();
            $measurement = (string) Str::ulid();
            $ids[] = $measurement;
            DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $organization->id, 'brand_id' => $item->id, 'version' => 1, 'questions' => '[{}]', 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $organization->id, 'brand_id' => $item->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('measurement_answers')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'brand_id' => $item->id, 'measurement_id' => $measurement, 'question_n' => 1, 'provider' => 'fake', 'requested_model' => 'fake', 'run_no' => 1, 'status' => 'ok', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $page = $this->get('/vision/settings')->assertOk()->assertInertia(fn ($page) => $page->where('slotSummary.used', 2)->where('slotSummary.remaining', 298)->where('slotSummary.percent', 1)->has('slotSummary.history', 1)->where('slotSummary.history.0.id', $ids[0])->where('slotSummary.history.0.number', 1)->where('slotSummary.history.0.slots', 1)->where('slotSummary.history.0.questions', 1)->where('slotSummary.history.0.models', 1)->where('slotSummary.history.0.runs', 1));
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $page->inertiaProps('revision')];
        $this->withHeaders($headers)->put('/vision/settings/recipients', ['members' => [['id' => 'invalid', 'cabinet' => true, 'email' => false]]])->assertSessionHasErrors('members.0.id');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/vision/settings')->assertOk()->assertInertia(fn ($page) => $page->where('canManageRecipients', false));
        $this->post('/logout')->assertRedirect();
        $this->get('/vision/settings')->assertRedirect();
    }
}
