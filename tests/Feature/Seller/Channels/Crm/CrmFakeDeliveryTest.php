<?php

namespace Tests\Feature\Seller\Channels\Crm;

use App\Seller\Channels\Crm\CrmFakeDelivery;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class CrmFakeDeliveryTest extends LimitsTestCase
{
    private function hook(string $organization, string $brand): string
    {
        $id = (string) Str::ulid();
        DB::table('webhooks')->insert(['id' => $id, 'organization_id' => $organization, 'brand_id' => $brand,
            'url' => Crypt::encryptString('https://crm.example.test/hook'), 'secret' => Crypt::encryptString(str_repeat('a', 64)),
            'events' => json_encode(['seller.contact.captured']), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_fake_event_uses_outbox_mapping_and_signed_delivery(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand] = $this->cabinet();
        $hook = $this->hook($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->post('/seller/integrations/crm/'.$hook.'/test', ['simulate_failure' => false])->assertRedirect();
        $delivery = DB::table('webhook_deliveries')->where('webhook_id', $hook)->first();
        $this->assertSame('simulated', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(200, $delivery->status_code);
        $this->assertSame($delivery->event_id, DB::table('outbox_events')->value('id'));
        $body = json_decode($delivery->payload, true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('seller.contact.captured', $body['event']);
        $this->assertSame($brand->id, $body['brand_id']);
        $this->assertSame($delivery->event_id, $body['event_id']);
        $this->assertSame(hash_hmac('sha256', $delivery->payload, str_repeat('a', 64)), CrmFakeDelivery::signature($delivery->payload, str_repeat('a', 64)));
        $this->get('/seller/integrations/crm')->assertOk()->assertSee('simulated')->assertDontSee(str_repeat('a', 64));
        Http::assertNothingSent();
    }

    public function test_failed_fake_delivery_records_five_attempts_and_other_brand_cannot_send(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $hook = $this->hook($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->post('/seller/integrations/crm/'.$hook.'/test', ['simulate_failure' => true])->assertRedirect();
        $this->assertDatabaseHas('webhook_deliveries', ['webhook_id' => $hook, 'status' => 'failed', 'attempts' => 5, 'status_code' => 503]);
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->withHeader('X-Brand-Id', $other->id);
        $this->post('/seller/integrations/crm/'.$hook.'/test')->assertNotFound();
        $this->assertDatabaseCount('webhook_deliveries', 1);
    }

    public function test_invalid_input_and_member_cannot_send(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $hook = $this->hook($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->post('/seller/integrations/crm/'.$hook.'/test', ['simulate_failure' => 'maybe'])->assertSessionHasErrors('simulate_failure');
        [$member] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        $this->actingAs($member)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->post('/seller/integrations/crm/'.$hook.'/test')->assertForbidden();
        $this->assertDatabaseCount('outbox_events', 0);
    }
}
