<?php

namespace App\Seller\Channels\Crm;

use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use App\Tenancy\TenantContext;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** In-memory HTTP delivery only; the shared automatic webhook consumer remains disabled. */
final class CrmFakeDelivery
{
    public function test(TenantContext $context, string $webhookId, bool $fail = false): string
    {
        if (config('runtime.delivery_driver', 'fake') !== 'fake') {
            throw new LogicException('CRM test delivery requires the fake driver.');
        }
        $eventId = (string) Str::ulid();
        $conversationId = (string) Str::ulid();
        $event = new DomainEvent([
            'event_id' => $eventId, 'schema_version' => 1, 'type' => 'seller.contact.captured',
            'occurred_at' => now()->toIso8601String(), 'organization_id' => $context->organizationId,
            'brand_id' => $context->brandId, 'actor_id' => $context->actorId,
            'aggregate' => ['type' => 'conversation', 'id' => $conversationId, 'version' => 1],
            'data' => ['conversation_id' => $conversationId, 'contact_id' => (string) Str::ulid()],
        ]);
        DB::transaction(function () use ($context, $webhookId, $event): void {
            $this->hook($context, $webhookId, 'seller.contact.captured');
            app(NotificationDispatcher::class)->enqueue($event);
        });

        return $this->consume($context, $webhookId, $eventId, $fail);
    }

    public function consume(TenantContext $context, string $webhookId, string $eventId, bool $fail = false): string
    {
        if (config('runtime.delivery_driver', 'fake') !== 'fake') {
            throw new LogicException('CRM test delivery requires the fake driver.');
        }
        $prepared = DB::transaction(function () use ($context, $webhookId, $eventId): array {
            $row = DB::table('outbox_events')->where('id', $eventId)->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->first();
            if (! $row) {
                throw new LogicException('CRM event unavailable.');
            }
            $event = new DomainEvent(json_decode($row->envelope, true, 512, JSON_THROW_ON_ERROR));
            $e = $event->envelope;
            if ($e['event_id'] !== $eventId || $e['organization_id'] !== $context->organizationId || $e['brand_id'] !== $context->brandId
                || ! in_array($e['type'], ['seller.contact.captured', 'seller.conversation.handed_off'], true)) {
                throw new LogicException('CRM event identity mismatch.');
            }
            $hook = $this->hook($context, $webhookId, $e['type']);
            $existing = DB::table('webhook_deliveries')->where('webhook_id', $webhookId)->where('event_id', $eventId)->first(['id']);
            if ($existing) {
                return [$existing->id, null, null, null];
            }
            $body = json_encode($this->map($e), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $id = (string) Str::ulid();
            DB::table('webhook_deliveries')->insert([
                'id' => $id, 'organization_id' => $context->organizationId, 'brand_id' => $context->brandId,
                'webhook_id' => $webhookId, 'event_id' => $eventId, 'event' => $e['type'],
                'payload' => $body, 'status' => 'pending', 'attempts' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return [$id, Crypt::decryptString($hook->url), Crypt::decryptString($hook->secret), $body];
        });
        [$id, $url, $secret, $body] = $prepared;
        if ($body === null) {
            return $id;
        }
        $client = new Client(['handler' => new MockHandler(array_fill(0, 5, new Response($fail ? 503 : 200)))]);
        $signature = self::signature($body, $secret);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $code = null;
            try {
                $response = $client->post($url, ['body' => $body, 'http_errors' => false, 'headers' => [
                    'Content-Type' => 'application/json', 'X-Souzpir-Signature' => 'sha256='.$signature,
                    'X-Souzpir-Delivery' => $id,
                ]]);
                $code = $response->getStatusCode();
            } catch (Throwable) {
                // Never store remote details or secret-bearing exception text.
            }
            if ($code !== null && $code >= 200 && $code < 300) {
                DB::table('webhook_deliveries')->where('id', $id)->update(['status' => 'simulated', 'attempts' => $attempt,
                    'status_code' => $code, 'updated_at' => now()]);

                return $id;
            }
            DB::table('webhook_deliveries')->where('id', $id)->update(['attempts' => $attempt, 'status_code' => $code, 'updated_at' => now()]);
        }
        DB::table('webhook_deliveries')->where('id', $id)->update(['status' => 'failed', 'updated_at' => now()]);

        return $id;
    }

    public static function signature(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    private function hook(TenantContext $context, string $id, string $event): object
    {
        $hook = DB::table('webhooks')->where('id', $id)->where('organization_id', $context->organizationId)
            ->where('brand_id', $context->brandId)->first();
        if (! $hook || ! in_array($event, json_decode($hook->events, true, 32, JSON_THROW_ON_ERROR), true)) {
            throw new LogicException('CRM webhook unavailable.');
        }

        return $hook;
    }

    private function map(array $event): array
    {
        return [
            'event_id' => $event['event_id'], 'event' => $event['type'], 'occurred_at' => $event['occurred_at'],
            'brand_id' => $event['brand_id'], 'conversation_id' => $event['data']['conversation_id'] ?? null,
            'contact_id' => $event['data']['contact_id'] ?? null, 'task_id' => $event['data']['task_id'] ?? null,
            'reason' => $event['data']['reason'] ?? null,
        ];
    }
}
