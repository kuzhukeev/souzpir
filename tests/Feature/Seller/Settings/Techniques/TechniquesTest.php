<?php

namespace Tests\Feature\Seller\Settings\Techniques;

use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Settings\Techniques\TechniqueEditor;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Seller\Knowledge\Fixtures\PrototypeKnowledge;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TechniquesTest extends TenancyTestCase
{
    public function test_edit_publish_policy_and_statistics_from_saved_messages(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $this->enableSeller($org->id, $brand->id);
        $context = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        $data = $this->knowledge();
        app(TenantContextRunner::class)->run($context, function () use ($context, $data): void {
            $store = app(KnowledgeRepository::class);
            $draft = $store->createDraft($context, $data);
            $store->publish($context, $draft['id'], $draft['version']);
        });
        $with = $this->conversation($org->id, $brand->id, 'full_contact');
        $this->conversation($org->id, $brand->id, 'lost');
        DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
            'brand_id' => $brand->id, 'conversation_id' => $with, 'role' => 'agent', 'text' => 'Ответ',
            'meta' => json_encode(['goal' => ['trigger' => ['id' => 'price_increase_calc']]]), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $before = app(TenantContextRunner::class)->run($context, fn () => app(TechniqueEditor::class)->view($context));
        $this->get('/seller/scenario/techniques')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Seller/Settings/Techniques/Index')->where('draft', true)
            ->where('techniques.0.id', 'price_increase_calc')->where('techniques.0.uses', 1)
            ->where('techniques.0.conversion_with', 100)->where('techniques.0.conversion_without', 0));
        $this->post('/seller/scenario/techniques/price_increase_calc', [
            'version' => $before['version'], 'name' => 'Подорожание', 'idea' => 'Объяснить изменение цены',
            'examples' => ['Сравним цены'], 'max_uses_per_dialog' => 1, 'enabled' => false, 'is_base' => true,
            'when' => [['field' => 'stage', 'values' => 'budget'], ['field' => 'client_type', 'values' => 'newbie']],
            'not_when' => [['field' => 'signals.wants_only_prices', 'values' => 'true']],
        ])->assertRedirect('/seller/scenario/techniques');
        $view = app(TenantContextRunner::class)->run($context, fn () => app(TechniqueEditor::class)->view($context));
        self::assertTrue($view['draft']);
        self::assertFalse($view['techniques'][0]['enabled']);
        self::assertTrue($view['techniques'][0]['is_base']);
        $this->post('/seller/scenario/techniques/publish', ['release' => $view['release'], 'version' => $view['version']])->assertRedirect();
        $snapshot = app(TenantContextRunner::class)->run($context, fn () => app(KnowledgeRepository::class)->published($context));
        self::assertSame('Подорожание', $snapshot->trigger('price_increase_calc')['name']);
        $state = new LeadState;
        $state->setStage('budget');
        $state->setClientType('newbie');
        $state->inc('turns', 3);
        self::assertNotSame('price_increase_calc', (new Policy($snapshot))->pickTrigger($state, ['sentiment' => 'neutral'])['id'] ?? null);
        $this->assertDatabaseHas('audit_logs', ['action' => 'knowledge.release.published', 'brand_id' => $brand->id]);
        $beforeQuiet = app(TenantContextRunner::class)->run($context, fn () => app(TechniqueEditor::class)->view($context));
        $this->post('/seller/scenario/techniques/quiet', ['version' => $beforeQuiet['version']])->assertRedirect();
        $afterQuiet = app(TenantContextRunner::class)->run($context, fn () => app(TechniqueEditor::class)->view($context));
        self::assertFalse(collect($afterQuiet['techniques'])->firstWhere('id', 'shortage_with_reason')['enabled']);
    }

    public function test_editor_permissions_brand_and_input_errors(): void
    {
        [$owner, $org, $brand, $otherBrand, $membership] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->enableSeller($org->id, $brand->id);
        $context = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($context, function () use ($context): void {
            $store = app(KnowledgeRepository::class);
            $draft = $store->createDraft($context, $this->knowledge());
            $store->publish($context, $draft['id'], $draft['version']);
        });
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/scenario/techniques')->assertForbidden();
        $this->flushSession()->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/seller/scenario/techniques/price_increase_calc', ['version' => 0])->assertUnprocessable();
        $this->postJson('/seller/scenario/techniques/quiet', ['version' => -1])->assertUnprocessable();
        $this->postJson('/seller/scenario/techniques/publish', ['release' => 'bad', 'version' => 0])->assertUnprocessable();
        $this->post('/brand/switch', ['brand_id' => $otherBrand->id])->assertRedirect();
        $this->get('/seller/scenario/techniques')->assertNotFound();
    }

    private function knowledge(): array
    {
        $source = new PrototypeKnowledge(__DIR__.'/../../Knowledge/Fixtures/data', 0);
        $data = [];
        foreach (PrototypeKnowledge::FILES as $file) {
            $data[$file] = $source->all($file);
        }

        return $data;
    }

    private function enableSeller(string $organization, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization,
            'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function conversation(string $organization, string $brand, string $outcome): string
    {
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $organization, 'brand_id' => $brand,
            'outcome' => $outcome, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
