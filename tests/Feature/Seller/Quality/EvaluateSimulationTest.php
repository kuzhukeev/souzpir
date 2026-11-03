<?php

namespace Tests\Feature\Seller\Quality;

use App\Ai\CanonicalJson;
use App\Seller\Evaluation\FakeSimulation;
use App\Seller\Evaluation\Judge;
use App\Tenancy\TenantContext;
use Database\Seeders\Seller\Evaluation\SellerPersonaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class EvaluateSimulationTest extends TenancyTestCase
{
    public function test_fake_evaluation_and_permissions_are_brand_scoped(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(SellerPersonaSeeder::class)->seedForBrand($tenant);
        $turns = [['client' => 'Здравствуйте', 'agent' => 'Добрый день', 'meta' => []]];
        $run = app(FakeSimulation::class)->run($tenant, 'newbie_voronezh', $turns);
        $payload = json_decode(DB::table('seller_personas')->where('brand_id', $brand->id)->where('key', 'newbie_voronezh')->value('payload'), true);
        $directory = storage_path('app/judge-quality-'.Str::random(8));
        mkdir($directory, 0700, true);
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => $directory]);
        $answer = ['naturalness' => 4, 'goal' => 4, 'facts' => 5, 'objections' => 3, 'brevity' => 4, 'comment' => 'Тестовая оценка', 'issues' => []];
        file_put_contents($directory.'/'.CanonicalJson::hash(app(Judge::class)->request($turns, $payload)).'.json', json_encode(['result' => ['content' => json_encode($answer, JSON_UNESCAPED_UNICODE)]]));
        $url = '/seller/quality/evaluate-simulation';
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post($url)->assertSessionHasErrors('evaluation');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post($url)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post($url)->assertRedirect('/seller/quality/'.now($brand->timezone ?: 'UTC')->toDateString())->assertSessionHasNoErrors();
        $this->assertDatabaseHas('seller_evaluations', ['sim_run_id' => $run, 'brand_id' => $brand->id]);
        $this->post($url)->assertSessionHasErrors('evaluation');
        config(['runtime.ai_driver' => 'openrouter']);
        $this->post($url)->assertSessionHasErrors('evaluation');
        Http::assertNothingSent();
    }
}
