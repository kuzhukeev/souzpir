<?php

namespace Tests\Feature\SP_E1_10F;

use App\Shell\Integrations\Webhooks\DeliverFakeWebhook;
use App\Shell\Integrations\Webhooks\FakeWebhookTransport;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Tenancy\TenancyTestCase;

final class WebhookConfigurationTest extends TenancyTestCase
{
    public function test_owner_configures_webhook_and_records_fake_test_without_network_delivery(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        [$owner, , $first, $second] = $this->cabinet();
        $observed = [];
        $this->app['events']->listen(RequestHandled::class, static function (RequestHandled $event) use (&$observed): void {
            $observed[] = [$event->request->fullUrl(), $event->request->all(), $event->request->getContent(), $event->response->getContent()];
        });
        $secret = str_repeat('a', 64);
        $url = 'https://hooks.example.org/private-path';
        $this->actingAs($owner)->withSession(['brand_id' => $first->id])->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1'])
            ->post('/integrations/api/webhook-settings', [
                'url' => $url, 'events' => ['seller.lead.created'], 'secret' => $secret,
            ])->assertRedirect('/integrations/api');
        $hook = DB::table('webhooks')->first();
        $this->assertNotNull($hook);
        $this->assertNotSame($url, $hook->url);
        $this->assertNotSame($secret, $hook->secret);
        $this->assertStringNotContainsString($url, json_encode($observed));
        $this->assertStringNotContainsString($secret, json_encode($observed));
        $this->post('/integrations/api/webhook-settings/'.$hook->id.'/test')->assertRedirect('/integrations/api');
        $delivery = DB::table('webhook_deliveries')->where('webhook_id', $hook->id)->first();
        $this->assertSame('pending', $delivery->status);
        Queue::assertPushed(DeliverFakeWebhook::class, fn ($job) => $job->deliveryId === $delivery->id);
        (new DeliverFakeWebhook($delivery->id))->handle(app(FakeWebhookTransport::class));
        $this->assertDatabaseHas('webhook_deliveries', ['id' => $delivery->id, 'status' => 'simulated', 'attempts' => 1, 'status_code' => 200]);
        $this->post('/brand/switch', ['brand_id' => $second->id])->assertRedirect();
        $this->get('/integrations/api')->assertOk();
        $this->post('/integrations/api/webhook-settings/'.$hook->id.'/test')->assertNotFound();
        Http::assertNothingSent();
        Queue::assertPushed(DeliverFakeWebhook::class, 1);
    }

    public function test_viewer_cannot_configure_or_test_and_bad_input_is_rejected(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);
        $data = ['url' => 'https://hooks.example.org/path', 'events' => ['seller.lead.created'], 'secret' => str_repeat('b', 64)];
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1'])
            ->post('/integrations/api/webhook-settings', $data)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1'])
            ->post('/integrations/api/webhook-settings', [...$data, 'url' => 'http://localhost/private'])->assertSessionHasErrors('url');
        $this->post('/integrations/api/webhook-settings', [...$data, 'events' => ['unknown']])->assertSessionHasErrors('events.0');
        $this->assertDatabaseCount('webhooks', 0);
        $this->post('/integrations/api/webhook-settings', $data)->assertRedirect('/integrations/api');
        $hook = DB::table('webhooks')->first();
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])
            ->post('/integrations/api/webhook-settings/'.$hook->id.'/test')->assertForbidden();
    }
}
