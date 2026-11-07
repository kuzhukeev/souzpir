<?php

namespace Tests\Feature\Seller\Settings\Tools;

use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Seller\Runtime\ConfiguredToolHandler;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Seller\Knowledge\Fixtures\PrototypeKnowledge;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ToolSettingsTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function publish($owner, $org, $brand, $membership): void
    {
        $context = new TenantContext($org->id, $brand->id, $owner->id, $membership->id, source: 'job');
        app(TenantContextRunner::class)->run($context, function () use ($context): void {
            $source = new PrototypeKnowledge(base_path('tests/Feature/Seller/Knowledge/Fixtures/data'), 0);
            $data = [];
            foreach (PrototypeKnowledge::FILES as $file) {
                $data[$file] = $source->all($file);
            }
            $repo = app(DatabaseKnowledgeRepository::class);
            $draft = $repo->createDraft($context, $data);
            $repo->publish($context, $draft['id'], 1);
        });
    }

    public function test_settings_reasons_timer_and_brand_isolation(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00+00');
        Http::preventStrayRequests();
        try {
            [$owner, $org, $brand, $other, $member] = $this->cabinet();
            $this->enable($org->id, $brand->id);
            $this->enable($org->id, $other->id);
            $this->publish($owner, $org, $brand, $member);
            $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
            $this->get('/seller/scenario/handoff')->assertOk()->assertInertia(fn ($page) => $page->where('settings.reasons.deferred_insist.enabled', true));
            $reason = ['enabled' => false, 'priority' => 'urgent', 'message' => 'Передам коллеге', 'after' => 'simple'];
            $this->post('/seller/scenario/handoff', ['reasons' => ['deferred_insist' => $reason], 'timeout_minutes' => 30])->assertRedirect();
            $this->get('/seller/scenario/handoff')->assertOk()->assertInertia(fn ($page) => $page->where('settings.reasons.deferred_insist.enabled', false)->where('settings.reasons.deferred_insist.after', 'simple'));
            $this->post('/seller/scenario/tools', ['tools' => ['send_price' => false], 'requisites' => 'Тестовые реквизиты', 'delivery_rules' => [['city' => 'Тверь', 'price_min' => 100, 'price_max' => 200, 'days_min' => 1, 'days_max' => 2]]])->assertRedirect();
            $this->get('/seller/scenario/tools')->assertOk()->assertInertia(fn ($page) => $page->where('settings.tools.send_price', false)->where('settings.tools.escalate', true)->where('settings.delivery_rules.0.city', 'Тверь'));
            $conversation = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'stage' => 'handoff', 'state' => '{}', 'handed_off_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->artisan('seller:monitor-handoffs')->assertSuccessful();
            $this->assertDatabaseCount('seller_handoff_reminders', 0);
            Carbon::setTestNow(now()->addMinutes(31));
            $this->artisan('seller:monitor-handoffs')->assertSuccessful();
            $this->assertDatabaseHas('seller_handoff_reminders', ['conversation_id' => $conversation, 'status' => 'sent']);
            $this->assertDatabaseHas('seller_tasks', ['conversation_id' => $conversation, 'type' => 'reminder', 'priority' => 'urgent']);
            $handler = app(ConfiguredToolHandler::class);
            $tenant = new TenantContext($org->id, $brand->id, source: 'job');
            $this->assertSame('ready', $handler->calculateDelivery($tenant, ['city' => 'Тверь'])['status']);
            $this->assertSame('invalid_arguments', $handler->calculateDelivery($tenant, ['city' => 1])['status']);
            $this->post('/seller/scenario/tools', ['tools' => ['calc_delivery' => false], 'requisites' => '', 'delivery_rules' => []])->assertRedirect();
            $this->assertSame('unavailable', $handler->calculateDelivery($tenant, ['city' => 'Тверь'])['status']);
            $this->artisan('seller:monitor-handoffs')->assertSuccessful();
            $this->assertDatabaseCount('seller_handoff_reminders', 1);
            $this->assertDatabaseCount('seller_tasks', 1);
            $this->assertSame('not_configured', $handler->calculateDelivery(new TenantContext($org->id, $other->id, source: 'job'), ['city' => 'Тверь'])['status']);
            $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
            $this->get('/seller/scenario/tools')->assertOk()->assertInertia(fn ($page) => $page->where('settings.tools.send_price', true)->where('settings.requisites', ''));
            Http::assertNothingSent();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_accepted_handoff_does_not_send_reminder(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00+00');
        try {
            [$owner, $org, $brand] = $this->cabinet();
            $this->enable($org->id, $brand->id);
            $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
            $this->post('/seller/scenario/handoff', ['reasons' => [], 'timeout_minutes' => 10])->assertRedirect();
            $conversation = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'stage' => 'handoff', 'state' => '{}', 'handed_off_at' => now()->subMinutes(11), 'taken_at' => now(),
                'created_at' => now(), 'updated_at' => now()]);
            $this->artisan('seller:monitor-handoffs')->assertSuccessful();
            $this->assertDatabaseCount('seller_handoff_reminders', 0);
            $this->assertDatabaseCount('seller_tasks', 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_permissions_and_invalid_settings(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/scenario/tools', ['tools' => ['escalate' => false], 'requisites' => '', 'delivery_rules' => []])->assertSessionHasErrors('tools');
        $this->post('/seller/scenario/handoff', ['reasons' => [], 'timeout_minutes' => -1])->assertSessionHasErrors('timeout_minutes');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/scenario/tools')->assertForbidden();
        $this->post('/seller/scenario/tools', ['tools' => ['send_price' => false], 'requisites' => '', 'delivery_rules' => []])->assertForbidden();
    }
}
