<?php

namespace Tests\Feature\Seller\Knowledge\Releases;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReleaseScenarioRunTest extends TenancyTestCase
{
    public function test_fake_run_and_confirmation_are_brand_scoped(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([[$brand, 'good', 'Здравствуйте'], [$brand, 'bad', ''], [$other, 'foreign', 'Привет']] as [$item, $key, $message]) {
            DB::table('seller_personas')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'key' => $key, 'name' => $key, 'type' => 'edge', 'payload' => json_encode(['first_message' => $message]), 'max_turns' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $draft = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->first();
        $url = '/seller/knowledge/'.$draft->id;
        $this->post($url.'/scenarios')->assertRedirect();
        $this->assertSame(2, DB::table('seller_sim_runs')->where('brand_id', $brand->id)->count());
        $this->assertSame(0, DB::table('seller_sim_runs')->where('brand_id', $other->id)->count());
        $this->get('/seller/knowledge')->assertOk()->assertInertia(fn ($page) => $page->where('scenarioRun.passed', 0)->where('scenarioRun.failed', 2));
        $this->post($url.'/publish', ['version' => 1])->assertSessionHasErrors('confirm_failed');
        $this->post($url.'/publish', ['version' => 1, 'confirm_failed' => 'bad'])->assertSessionHasErrors('confirm_failed');
        $this->post($url.'/publish', ['version' => 1, 'confirm_failed' => true])->assertSessionHasNoErrors();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post($url.'/scenarios')->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post($url.'/scenarios')->assertNotFound();
    }
}
