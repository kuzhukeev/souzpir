<?php

namespace Tests\Feature\SellerRuntime\Delivery;

use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Runtime\Contracts\ChannelDelivery;
use App\Seller\Runtime\Contracts\DeliveryCommand;
use App\Seller\Runtime\Contracts\DeliveryReceipt;
use App\Seller\Runtime\Delivery\FakeChannelDelivery;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\SellerRuntime\Turns\ProtectedFollowupFixtures;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FakeDeliveryTest extends TenancyTestCase
{
    use ProtectedFollowupFixtures;

    public function test_only_completed_bot_turn_invokes_fake_delivery(): void
    {
        config(['runtime.ai_driver' => 'fake', 'cache.default' => 'array']);
        [$owner, $org, $brand, , $member] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
            'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $context = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $this->seedProtected($context);
        $spy = new class implements ChannelDelivery
        {
            public array $commands = [];

            public function send(DeliveryCommand $command): DeliveryReceipt
            {
                $this->commands[] = $command;

                return (new FakeChannelDelivery)->send($command);
            }
        };
        app()->instance(ChannelDelivery::class, $spy);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        foreach ([['bot', 1, 'completed'], ['suggest', 0, 'suggested'], ['manager', 0, 'suppressed']] as [$mode, $version, $status]) {
            [$conversation, $message] = app(TenantContextRunner::class)->run($context, function () use ($mode, $version): array {
                $conversation = Conversation::create(['state' => [], 'mode' => $mode, 'version' => $version]);
                $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Вопрос', 'meta' => []]);

                return [$conversation, $message];
            });
            $input = ['conversation_id' => $conversation->id, 'message_id' => $message->id, 'text' => $message->text,
                'idempotency_key' => 'delivery-'.$mode, 'expected_version' => $version];
            $this->postJson('/seller/turns/runtime', $input)->assertOk()->assertJsonPath('data.status', $status);
        }
        self::assertCount(1, $spy->commands);
        self::assertSame('fake', $spy->commands[0]->channel);
    }

    public function test_fake_delivery_replays_and_keeps_brands_isolated(): void
    {
        Cache::store('array')->flush();
        config(['cache.default' => 'array']);
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        $context = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $otherContext = new TenantContext($org->id, $other->id, $owner->id, $member->id);
        [$conversation, $message, $client, $another] = app(TenantContextRunner::class)->run($context, function (): array {
            $conversation = Conversation::create(['state' => [], 'mode' => 'bot']);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'agent', 'text' => 'Ответ', 'meta' => []]);
            $client = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Вопрос', 'meta' => []]);
            $another = Message::create(['conversation_id' => $conversation->id, 'role' => 'agent', 'text' => 'Другой ответ', 'meta' => []]);

            return [$conversation, $message, $client, $another];
        });
        $delivery = new FakeChannelDelivery;
        $command = new DeliveryCommand($context, $conversation->id, $message->id, 'fake', 'turn-key');
        self::assertSame('fake_sent', $delivery->send($command)->status);
        self::assertSame($message->id, $delivery->send($command)->messageId);
        foreach ([
            new DeliveryCommand($otherContext, $conversation->id, $message->id, 'fake', 'other'),
            new DeliveryCommand($context, $conversation->id, $client->id, 'fake', 'client'),
            new DeliveryCommand($context, $conversation->id, $message->id, 'telegram', 'live'),
            new DeliveryCommand($context, $conversation->id, $message->id, 'fake', ''),
            new DeliveryCommand($context, $conversation->id, $another->id, 'fake', 'turn-key'),
        ] as $invalid) {
            try {
                $delivery->send($invalid);
                self::fail('Invalid delivery was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
    }
}
