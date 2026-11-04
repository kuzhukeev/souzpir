<?php

namespace Tests\Feature\SellerRuntime\Turns;

use App\Ai\Drivers\Driver;
use App\Ai\Drivers\FakeDriver;
use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Channels\Core\ChannelInboundEvent;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Contact;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Seller\Runtime\FirstInboundRuntime;
use App\Seller\Settings\BrandSellerSettings;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Ai\PromptSetsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class GreetingFakeDriver implements Driver
{
    public int $calls = 0;

    public function complete(#[\SensitiveParameter] array $body): array
    {
        $text = $this->calls++ % 2 === 0 ? '{"client_intent":"answer_only"}' : 'Можем обсудить заказ?';

        return ['content' => $text, 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
            'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
    }
}

final class GreetingFirstTurnTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_saved_greeting_is_added_to_first_fake_turn_and_empty_text_falls_back(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Queue::fake();
        config(['runtime.ai_driver' => 'fake']);
        app(PromptSetsSeeder::class)->run();
        $owner = User::create(['name' => 'Synthetic', 'email' => Str::random(12).'@example.test', 'password' => 'synthetic-test-password']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $owner->refresh();
        $org = Organization::create(['name' => 'Synthetic', 'owner_user_id' => $owner->id]);
        [$membership, $brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), fn () => [
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']), Brand::create(['name' => 'Synthetic brand', 'timezone' => 'Europe/Moscow']),
        ]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        [$conversation, $input] = app(TenantContextRunner::class)->run($tenant, function () use ($tenant): array {
            app(YamlKnowledgeImporter::class)->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data'));
            $settings = new BrandSellerSettings;
            $settings->greetings = ['api' => 'Здравствуйте, {имя}! Вы на странице {страница}.'];
            $settings->save();
            $contact = Contact::create(['name' => 'Анна']);
            $conversation = Conversation::create(['state' => [], 'mode' => 'bot', 'contact_id' => $contact->id]);
            $input = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Тестовый вопрос', 'meta' => ['page' => 'catalog']]);

            return [$conversation, $input];
        });
        $fake = new GreetingFakeDriver;
        app()->instance(FakeDriver::class, $fake);
        $session = new Store('greeting-test', new ArraySessionHandler(120));
        $session->setId(Str::random(40));
        $session->start();
        $session->put('auth.version', $owner->auth_version);
        $request = Request::create('/', 'POST');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $owner);
        app(AuthSessionRegistry::class)->issue($request, $owner, $owner->auth_version);
        app(AuthSessionRegistry::class)->withinActive($request, fn () => app(SessionBrandSelection::class)->initialize($request, $org->id, $brand->id));
        $selection = app(AuthSessionRegistry::class)->withinActive($request, fn () => app(SessionBrandSelection::class)->current($request));
        $request->attributes->set('tenant.brand_revision', (string) $selection->brand_revision);
        $command = new TurnCommand($conversation->id, $input->id, $input->text, null, 'greeting-first', 0);
        $run = fn (TurnCommand $turn) => app(TenantContextRunner::class)->run($tenant, fn () => app(FirstInboundRuntime::class)->turn($request, $turn));
        self::assertSame('completed', $run($command)['status']);
        $reply = DB::table('messages')->where('conversation_id', $conversation->id)->where('role', 'agent')->value('text');
        self::assertStringStartsWith('Здравствуйте, Анна! Вы на странице Каталог.', $reply);
        self::assertStringContainsString('Можем обсудить заказ?', $reply);
        self::assertSame('completed', $run($command)['status']);
        self::assertSame(1, DB::table('messages')->where('conversation_id', $conversation->id)->where('role', 'agent')->count());
        self::assertSame(2, $fake->calls);
        [$emptyConversation, $emptyInput] = app(TenantContextRunner::class)->run($tenant, function (): array {
            $settings = BrandSellerSettings::query()->firstOrFail();
            $settings->greetings = ['api' => '  '];
            $settings->save();
            $conversation = Conversation::create(['state' => [], 'mode' => 'bot']);
            $input = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Другой вопрос', 'meta' => []]);

            return [$conversation, $input];
        });
        $empty = new TurnCommand($emptyConversation->id, $emptyInput->id, $emptyInput->text, null, 'greeting-empty', 0);
        self::assertSame('completed', $run($empty)['status']);
        self::assertSame('Можем обсудить заказ?', DB::table('messages')->where('conversation_id', $emptyConversation->id)->where('role', 'agent')->value('text'));
        self::assertSame(4, $fake->calls);
        $channels = ['telegram', 'widget', 'api', 'email', 'whatsapp'];
        app(TenantContextRunner::class)->run($tenant, function () use ($channels): void {
            $settings = BrandSellerSettings::query()->firstOrFail();
            $settings->greetings = array_combine($channels, array_map(fn (string $channel) => 'Канал '.$channel, $channels));
            $settings->greetings = [...$settings->greetings, 'widget_catalog' => 'Канал widget: {страница}'];
            $settings->save();
        });
        foreach ($channels as $channel) {
            [$nextConversation, $nextInput, $eventId] = app(TenantContextRunner::class)->run($tenant, function () use ($channel): array {
                $account = ChannelAccount::create(['type' => $channel, 'status' => 'active']);
                $conversation = Conversation::create(['state' => [], 'mode' => 'bot', 'channel_account_id' => $account->id]);
                $event = ChannelInboundEvent::create(['channel_account_id' => $account->id, 'external_id' => 'greeting-'.$channel, 'payload' => ['page' => 'catalog']]);
                $input = Message::create(['conversation_id' => $conversation->id, 'channel_account_id' => $account->id,
                    'external_event_id' => $event->id, 'role' => 'client', 'text' => 'Ещё вопрос', 'meta' => []]);

                return [$conversation, $input, $event->id];
            });
            $turn = new TurnCommand($nextConversation->id, $nextInput->id, $nextInput->text, $eventId, 'greeting-'.$channel, 0);
            self::assertSame('completed', $run($turn)['status']);
            self::assertStringStartsWith($channel === 'widget' ? 'Канал widget: Каталог' : 'Канал '.$channel, DB::table('messages')->where('conversation_id', $nextConversation->id)->where('role', 'agent')->value('text'));
        }
        self::assertSame(14, $fake->calls);
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }
}
