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
        DB::table('seller_personas')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'key' => 'preset', 'name' => 'Пресет', 'type' => 'edge', 'payload' => '{}', 'max_turns' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/seller/quality/scenarios', [...$this->input(), 'id' => $id])->assertForbidden();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/quality/scenarios')->assertForbidden();
        $this->post('/seller/quality/scenarios', $this->input())->assertForbidden();
    }
}
