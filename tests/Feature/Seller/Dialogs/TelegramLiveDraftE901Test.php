<?php

namespace Tests\Feature\Seller\Dialogs;

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;
use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

/** SP-E9-01 сценарий 3 в режиме запуска (LV-01): входящее Telegram → черновик → отправка → перехват → возврат. */
final class TelegramLiveDraftE901Test extends TenancyTestCase
{
    public function test_inbound_telegram_message_produces_a_draft_that_is_sent_then_taken_and_returned(): void
    {
        Http::preventStrayRequests();
        config(['features.LV-01.defaults.enabled' => true]);
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);

        $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
        $data['facts'] = [['id' => 'f1', 'text' => 'Доставка по Москве бесплатная']];
        $knowledge = Mockery::mock(KnowledgeRepository::class);
        $knowledge->shouldReceive('published')->andReturn(new KnowledgeSnapshot($data, 'r1', str_repeat('a', 64)));
        $this->app->instance(KnowledgeRepository::class, $knowledge);
        $reply = 'Да, по Москве доставка бесплатная.';
        $this->app->instance(AiGateway::class, new class($reply) implements AiGateway
        {
            public function __construct(private string $reply) {}

            public function complete(AiRequest $request, AiContext $context): AiResult
            {
                return new AiResult($this->reply, [], [], $request->model, 1, 0, 'call', null);
            }
        });

        $this->put('/seller/channels/telegram', ['name' => 'Бот', 'token' => '123456:ABCDE_abcde', 'mode' => 'suggest'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $account = ChannelAccount::withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();

        // Входящее сообщение.
        $payload = ['update_id' => 201, 'message' => ['message_id' => 9, 'chat' => ['id' => 555, 'type' => 'private'], 'text' => 'Есть доставка?']];
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $account->credentials['webhook_secret'])
            ->postJson(route('seller.telegram.webhook', $account->id), $payload)->assertOk();

        $dialog = DB::table('conversations')->where('channel_account_id', $account->id)->first();
        self::assertNotNull($dialog);
        self::assertSame('suggest', $dialog->mode);

        // Черновик от LiveDraftJob (queue sync, слушатель eloquent.created из конфигурации LV-01).
        $turn = DB::table('seller_turns')->where('conversation_id', $dialog->id)->first();
        self::assertNotNull($turn, 'черновик не создан по входящему сообщению');
        self::assertSame('suggested', $turn->status);
        $outcome = json_decode($turn->outcome, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($reply, $outcome['messages'][0]['text']);

        // Отправка черновика менеджером (SP-L1-05), без взятия диалога.
        $this->post("/seller/dialogs/{$dialog->id}/suggestions/{$turn->id}/send", ['text' => $reply])->assertRedirect();
        self::assertSame('sent', DB::table('seller_turns')->where('id', $turn->id)->value('status'));
        $sent = DB::table('messages')->where('conversation_id', $dialog->id)->where('role', 'manager')->first();
        self::assertNotNull($sent);
        self::assertSame($reply, $sent->text);

        // Перехват: менеджер забирает диалог себе.
        $this->post("/seller/dialogs/{$dialog->id}/mode", ['action' => 'take'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('conversations', ['id' => $dialog->id, 'mode' => 'manager', 'assigned_user_id' => $owner->id]);

        // Возврат боту.
        $this->post("/seller/dialogs/{$dialog->id}/mode", ['action' => 'return'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('conversations', ['id' => $dialog->id, 'mode' => 'bot', 'assigned_user_id' => null]);

        Http::assertNothingSent();
    }
}
