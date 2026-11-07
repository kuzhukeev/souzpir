<?php

namespace Tests\Feature\Shell\Integrations\Webhooks;

use App\Shell\Integrations\Webhooks\DeliverFakeWebhook;
use App\Shell\Integrations\Webhooks\FakeWebhookTransport;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FakeWebhookQueueE110JTest extends TenancyTestCase
{
    public function test_manual_test_queues_signed_fake_delivery_and_retries(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        $id = (string) Str::ulid();
        $secret = str_repeat('a', 64);
        DB::table('webhooks')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'url' => Crypt::encryptString('https://example.test/hook'), 'secret' => Crypt::encryptString($secret),
            'events' => json_encode(['seller.contact.captured']), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->post('/integrations/api/webhook-settings/'.$id.'/test')->assertRedirect();
        $delivery = DB::table('webhook_deliveries')->where('webhook_id', $id)->first();
        self::assertSame('pending', $delivery->status);
        Queue::assertPushed(DeliverFakeWebhook::class);
        $transport = $this->mock(FakeWebhookTransport::class);
        $transport->shouldReceive('send')->twice()->withArgs(function ($url, $body, $headers) use ($secret, $delivery) {
            return $url === 'https://example.test/hook' && json_decode($body, true)['event'] === 'webhook.test'
                && $headers['X-Souzpir-Signature'] === 'sha256='.hash_hmac('sha256', $body, $secret)
                && $headers['X-Souzpir-Delivery'] === $delivery->id;
        })->andReturn(503, 200);
        (new DeliverFakeWebhook($delivery->id))->handle($transport);
        $this->assertDatabaseHas('webhook_deliveries', ['id' => $delivery->id, 'status' => 'simulated', 'attempts' => 2, 'status_code' => 200]);
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->withHeader('X-Brand-Id', $other->id);
        $this->post('/integrations/api/webhook-settings/'.$id.'/test')->assertNotFound();
        Http::assertNothingSent();
    }
}
