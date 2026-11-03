<?php

namespace Tests\Feature\Seller\Scenarios;

use App\Seller\Evaluation\FakeSimulation;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ScenariosTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function input(): array
    {
        return ['name' => 'Новый клиент', 'type' => 'newbie', 'behavior' => 'Спрашивает о доставке', 'first_message' => '', 'max_turns' => 8, 'expect' => ['full_card', 'no_argument']];
    }

    public function test_create_update_and_brand_isolation(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/quality/scenarios', $this->input())->assertRedirect()->assertSessionHasNoErrors();
        $id = DB::table('seller_personas')->where('brand_id', $brand->id)->value('id');
        $this->get('/seller/quality/scenarios')->assertOk()->assertInertia(fn ($page) => $page->where('scenarios.0.name', 'Новый клиент')->where('scenarios.0.expect.0', 'full_card')->where('scenarios.0.editable', true));
        $this->post('/seller/quality/scenarios', [...$this->input(), 'id' => $id, 'name' => 'Изменён'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('seller_personas', ['id' => $id, 'name' => 'Изменён', 'brand_id' => $brand->id]);
        $key = DB::table('seller_personas')->where('id', $id)->value('key');
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, null, source: 'job');
        app(TenantContextRunner::class)->run($tenant, fn () => app(FakeSimulation::class)->run($tenant, $key, [['client' => 'Вопрос', 'agent' => 'Ответ', 'meta' => []]]));
        $this->get('/seller/quality/scenarios')->assertOk()->assertInertia(fn ($page) => $page->where('runs.0.turns.0.client', 'Вопрос'));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/quality/scenarios')->assertOk()->assertInertia(fn ($page) => $page->has('scenarios', 0));
        $this->post('/seller/quality/scenarios', [...$this->input(), 'id' => $id])->assertNotFound();
    }

    public function test_validation_presets_and_permissions(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/quality/scenarios', [...$this->input(), 'max_turns' => 0])->assertSessionHasErrors('max_turns');
        $this->post('/seller/quality/scenarios', [...$this->input(), 'expect' => ['invented']])->assertSessionHasErrors('expect.0');
        $id = (string) Str::ulid();
        DB::table('seller_personas')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'key' => 'preset', 'name' => 'Пресет', 'type' => 'edge', 'payload' => '{"expect":["no_pct_above_grid"]}', 'max_turns' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/seller/quality/scenarios', [...$this->input(), 'id' => $id])->assertForbidden();
        $this->post("/seller/quality/scenarios/{$id}/copy")->assertRedirect();
        $copyId = DB::table('seller_personas')->where('name', 'Копия: Пресет')->value('id');
        $this->post('/seller/quality/scenarios', [...$this->input(), 'id' => $copyId,
            'expect' => ['no_pct_above_grid'], 'expected_result' => 'refusal'])->assertSessionHasNoErrors();
        $this->assertSame(['no_pct_above_grid'], json_decode(DB::table('seller_personas')->where('id', $copyId)->value('payload'), true)['expect']);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/quality/scenarios')->assertForbidden();
        $this->post('/seller/quality/scenarios', $this->input())->assertForbidden();
    }

    public function test_copy_membership_and_editor_fields_stay_in_current_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/quality/scenarios', [...$this->input(), 'who' => 'Покупатель', 'want' => 'Узнать цену',
            'goal' => 'Получить контакт', 'expected_result' => 'handoff'])->assertSessionHasNoErrors();
        $id = DB::table('seller_personas')->where('brand_id', $brand->id)->value('id');
        $this->get('/seller/quality/scenarios')->assertInertia(fn ($page) => $page->where('scenarios.0.who', 'Покупатель')
            ->where('scenarios.0.goal', 'Получить контакт')->where('scenarios.0.expected_result', 'handoff')
            ->where('scenarios.0.in_set', true));

        $this->patch("/seller/quality/scenarios/{$id}/set", ['in_set' => false])->assertRedirect()->assertSessionHasNoErrors();
        $this->post("/seller/quality/scenarios/{$id}/copy")->assertRedirect();
        $copies = DB::table('seller_personas')->where('brand_id', $brand->id)->get();
        $this->assertCount(2, $copies);
        $copy = $copies->firstWhere('name', 'Копия: Новый клиент');
        $this->assertNotNull($copy);
        $this->assertFalse(json_decode($copy->payload, true)['in_set']);
        $this->assertSame('handoff', json_decode($copy->payload, true)['expected_result']);

        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/quality/scenarios')->assertInertia(fn ($page) => $page->has('scenarios', 0));
        $this->post("/seller/quality/scenarios/{$id}/copy")->assertNotFound();
        $this->patch("/seller/quality/scenarios/{$id}/set", ['in_set' => true])->assertNotFound();
    }

    public function test_latest_result_validation_and_viewer_rights(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/quality/scenarios', $this->input())->assertSessionHasNoErrors();
        $persona = DB::table('seller_personas')->where('brand_id', $brand->id)->first();
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, null, source: 'job');
        $runId = app(TenantContextRunner::class)->run($tenant, fn () => app(FakeSimulation::class)->run($tenant, $persona->key,
            [['client' => 'Цена?', 'agent' => 'Ответ', 'meta' => []]]));
        DB::table('seller_evaluations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
            'brand_id' => $brand->id, 'sim_run_id' => $runId, 'scores' => '{}', 'issues' => '[]',
            'average' => 4.5, 'comment' => 'Хорошо', 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/seller/quality/scenarios')->assertInertia(fn ($page) => $page->where('scenarios.0.last_result.result', 'passed')
            ->where('runs.0.comment', 'Хорошо'));
        $this->patch("/seller/quality/scenarios/{$persona->id}/set", [])->assertSessionHasErrors('in_set');
        $this->post('/seller/quality/scenarios', [...$this->input(), 'expected_result' => 'invented'])->assertSessionHasErrors('expected_result');

        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->post("/seller/quality/scenarios/{$persona->id}/copy")->assertForbidden();
        $this->patch("/seller/quality/scenarios/{$persona->id}/set", ['in_set' => false])->assertForbidden();
    }
}
