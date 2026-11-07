<?php

namespace Tests\Feature\Seller\Evaluation;

use App\Seller\Evaluation\Jobs\SimulateFakeJob;
use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SavedScenarioE408KTest extends TenancyTestCase
{
    public function test_saved_sandbox_scenario_replays_every_client_turn_without_working_dialogues(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, , $member] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $repo = app(DatabaseKnowledgeRepository::class);
            $draft = $repo->createDraft($tenant, array_fill_keys(KnowledgeSnapshot::FILES, []));
            $repo->publish($tenant, $draft['id'], 1);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $transcript = "Клиент: Первый вопрос\nБот: Первый ответ\n\nКлиент: Второй вопрос\nБот: Второй ответ\n\nКлиент: Третий вопрос\nБот: Третий ответ";
        $this->post('/seller/quality/scenarios', ['name' => 'Тест из песочницы', 'type' => 'edge', 'behavior' => $transcript, 'first_message' => 'Первый вопрос', 'max_turns' => 3, 'expect' => []])->assertRedirect()->assertSessionHasNoErrors();
        $persona = DB::table('seller_personas')->where('brand_id', $brand->id)->where('name', 'Тест из песочницы')->first();
        config(['runtime.ai_driver' => 'fake', 'features.E3-06D.defaults.runtime_enabled' => true]);
        SimulateFakeJob::dispatchSync($org->id, $brand->id, $persona->key, [[], [], []]);
        $run = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->sole();
        $turns = json_decode($run->turns, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('completed', $run->status);
        self::assertSame(['Первый вопрос', 'Второй вопрос', 'Третий вопрос'], array_column($turns, 'client'));
        foreach ($turns as $turn) {
            self::assertTrue($turn['meta']['preview_only']);
        }
        self::assertDatabaseCount('conversations', 0);
        self::assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }
}
