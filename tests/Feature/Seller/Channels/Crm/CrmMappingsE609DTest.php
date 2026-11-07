<?php

namespace Tests\Feature\Seller\Channels\Crm;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class CrmMappingsE609DTest extends LimitsTestCase
{
    public function test_shared_configuration_creates_crm_hook_without_exposing_secret(): void
    {
        Http::preventStrayRequests();
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id)
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        $secret = str_repeat('b', 64);
        $this->post('/integrations/api/webhook-settings', ['url' => 'https://hooks.example.org/hook', 'secret' => $secret,
            'events' => ['seller.contact.captured', 'seller.conversation.handed_off']])->assertRedirect('/integrations/api');
        $hook = DB::table('webhooks')->first();
        $this->assertNotSame($secret, $hook->secret);
        $this->get('/seller/integrations/crm')->assertOk()->assertDontSee($secret)
            ->assertInertia(fn (Assert $page) => $page->where('webhooks.0.id', $hook->id)->has('webhooks.0.events', 2));
        Http::assertNothingSent();
    }

    public function test_mapping_is_saved_per_brand_and_used_by_fake_delivery(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        $hook = (string) Str::ulid();
        DB::table('webhooks')->insert(['id' => $hook, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'url' => Crypt::encryptString('https://crm.example.test/hook'), 'secret' => Crypt::encryptString(str_repeat('a', 64)), 'events' => json_encode(['seller.contact.captured']), 'created_at' => now(), 'updated_at' => now()]);
        $fields = ['name' => 'customer_name', 'phone' => 'customer_phone', 'email' => 'customer_email'];
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->post('/seller/integrations/crm/'.$hook.'/mappings', ['fields' => $fields])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/integrations/crm')->assertOk()->assertInertia(fn (Assert $page) => $page->where('webhooks.0.mappings', $fields));
        $this->post('/seller/integrations/crm/'.$hook.'/test')->assertRedirect();
        $payload = json_decode(DB::table('webhook_deliveries')->where('webhook_id', $hook)->value('payload'), true, 32, JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($payload['customer_name']);
        $this->assertNotEmpty($payload['customer_phone']);
        $this->assertArrayHasKey('customer_email', $payload);
        $this->assertArrayNotHasKey('name', $payload);
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->withHeader('X-Brand-Id', $other->id);
        $this->post('/seller/integrations/crm/'.$hook.'/mappings', ['fields' => $fields])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_handoff_only_hook_receives_a_matching_fake_event(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $hook = (string) Str::ulid();
        DB::table('webhooks')->insert(['id' => $hook, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'url' => Crypt::encryptString('https://crm.example.test/hook'), 'secret' => Crypt::encryptString(str_repeat('a', 64)), 'events' => json_encode(['seller.conversation.handed_off']), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id)
            ->post('/seller/integrations/crm/'.$hook.'/test')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('webhook_deliveries', ['webhook_id' => $hook, 'event' => 'seller.conversation.handed_off', 'status' => 'simulated']);
    }

    public function test_invalid_or_forbidden_mappings_are_not_saved(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $hook = (string) Str::ulid();
        DB::table('webhooks')->insert(['id' => $hook, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'url' => Crypt::encryptString('https://crm.example.test/hook'), 'secret' => Crypt::encryptString(str_repeat('a', 64)), 'events' => json_encode(['seller.contact.captured']), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->post('/seller/integrations/crm/'.$hook.'/mappings', ['fields' => ['name' => 'same', 'phone' => 'same', 'email' => 'other']])->assertSessionHasErrors();
        $this->post('/seller/integrations/crm/'.$hook.'/mappings', ['fields' => ['name' => 'not valid', 'phone' => 'contact', 'email' => 'task']])->assertSessionHasErrors();
        [$member] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        $this->actingAs($member)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->post('/seller/integrations/crm/'.$hook.'/mappings', ['fields' => ['name' => 'deal', 'phone' => 'contact', 'email' => 'task']])->assertForbidden();
        $this->assertDatabaseCount('crm_webhook_mappings', 0);
    }
}
