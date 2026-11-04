<?php

namespace Tests\Feature\SellerRuntime;

use App\Ai\Drivers\Driver;
use App\Ai\Drivers\FakeDriver;
use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Seller\Runtime\FirstInboundRuntime;
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

final class RuntimeFakeDriver implements Driver
{
    public function __construct(private bool $unknown = false) {}

    public int $calls = 0;

    public array $bodies = [];

    public function complete(#[\SensitiveParameter] array $body): array
    {
        $this->bodies[] = $body;
        $content = $this->calls++ === 0 ? '{"client_intent":"answer_only"}' : 'Можем обсудить заказ?';

        return ['content' => $content, 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
            'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => $this->unknown ? null : 0];
    }
}

final class FirstInboundRuntimeTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Queue::fake();
        config(['runtime.ai_driver' => 'fake']);
    }

    private function fixture(string $mode = 'bot', string $role = 'owner'): array
    {
        app(PromptSetsSeeder::class)->run();
        $owner = User::create(['name' => 'Synthetic', 'email' => Str::random(12).'@example.test', 'password' => 'synthetic-test-password']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $owner->refresh();
        $org = Organization::create(['name' => 'Synthetic', 'owner_user_id' => $owner->id]);
        [$member, $brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), fn () => [
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']), Brand::create(['name' => 'Synthetic brand', 'timezone' => 'Europe/Moscow']),
        ]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $ownerContext = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $user = $owner;
        if ($role === 'viewer') {
            $user = User::create(['name' => 'Viewer', 'email' => Str::random(12).'@example.test', 'password' => 'synthetic-test-password']);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->refresh();
            $member = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), fn () => Membership::create(['user_id' => $user->id, 'role' => 'viewer', 'brand_ids' => [$brand->id]]));
        }
        $context = new TenantContext($org->id, $brand->id, $user->id, $member->id);
        [$conversation, $message] = app(TenantContextRunner::class)->run($ownerContext, function () use ($ownerContext, $mode): array {
            app(YamlKnowledgeImporter::class)->importAndPublish($ownerContext, base_path('tests/Feature/Seller/Knowledge/Fixtures/data'));
            $conversation = Conversation::create(['state' => [], 'mode' => $mode]);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Вопрос от synthetic-contact@example.test', 'meta' => []]);

            return [$conversation, $message];
        });
        $session = new Store('runtime-test', new ArraySessionHandler(120));
        $session->setId(Str::random(40));
        $session->start();
        $session->put('auth.version', $user->auth_version);
        $request = Request::create('/', 'POST');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
        app(AuthSessionRegistry::class)->withinActive($request, fn () => app(SessionBrandSelection::class)->initialize($request, $org->id, $brand->id));
        $selection = app(AuthSessionRegistry::class)->withinActive($request, fn () => app(SessionBrandSelection::class)->current($request));
        $request->attributes->set('tenant.brand_revision', (string) $selection->brand_revision);
        $command = new TurnCommand($conversation->id, $message->id, $message->text, null, 'runtime-test-key', 0);

        return [$context, $request, $command];
    }

    private function turn(TenantContext $tenant, #[\SensitiveParameter] Request $request, #[\SensitiveParameter] TurnCommand $command): array
    {
        return app(TenantContextRunner::class)->run($tenant, fn () => app(FirstInboundRuntime::class)->turn($request, $command));
    }

    public function test_bot_first_turn_reuses_protected_result_and_masks_contacts(): void
    {
        [$context, $request, $command] = $this->fixture();
        $fake = new RuntimeFakeDriver;
        app()->instance(FakeDriver::class, $fake);
        $first = $this->turn($context, $request, $command);
        self::assertSame('completed', $first['status']);
        self::assertSame($first, $this->turn($context, $request, $command));
        self::assertSame(2, $fake->calls);
        self::assertStringNotContainsString('synthetic-contact@example.test', json_encode($fake->bodies));
        self::assertSame(1, DB::table('seller_turns')->count());
        $other = app(TenantContextRunner::class)->run($context, function () use ($command): TurnCommand {
            $conversation = Conversation::create(['state' => [], 'mode' => 'bot']);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'another-private@example.test', 'meta' => []]);

            return new TurnCommand($conversation->id, $message->id, $message->text, null, $command->idempotencyKey, 0);
        });
        try {
            $this->turn($context, $request, $other);
            self::fail('Different input reused an idempotency key.');
        } catch (IdempotencyConflict $error) {
            self::assertStringNotContainsString('another-private@example.test', (string) $error);
            $trace = $error->getTrace();
            foreach ($trace as $frame) {
                if (($frame['class'] ?? null) === FirstInboundRuntime::class && ($frame['function'] ?? null) === 'turn') {
                    if (isset($frame['args'])) {
                        self::assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][0]);
                        self::assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][1]);
                    }
                }
            }
            array_walk_recursive($trace, static function ($value): void {
                if (is_string($value)) {
                    self::assertStringNotContainsString('another-private@example.test', $value);
                }
            });
        }
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_uncertain_fake_result_is_not_retried_or_finalized(): void
    {
        [$context, $request, $command] = $this->fixture();
        $fake = new RuntimeFakeDriver(true);
        app()->instance(FakeDriver::class, $fake);
        self::assertSame('uncertain', $this->turn($context, $request, $command)['status']);
        self::assertSame('uncertain', $this->turn($context, $request, $command)['status']);
        self::assertSame(1, $fake->calls);
        self::assertSame(0, DB::table('messages')->where('role', 'agent')->count());
        Http::assertNothingSent();
    }

    public function test_manager_is_silent_and_tampered_input_is_rejected(): void
    {
        [$context, $request, $command] = $this->fixture('manager');
        $fake = new RuntimeFakeDriver;
        app()->instance(FakeDriver::class, $fake);
        $first = $this->turn($context, $request, $command);
        self::assertSame('suppressed', $first['status']);
        self::assertSame(1, $first['version']);
        self::assertSame($first, $this->turn($context, $request, $command));
        $next = app(TenantContextRunner::class)->run($context, function () use ($command): TurnCommand {
            $message = Message::create(['conversation_id' => $command->conversationId, 'role' => 'client', 'text' => 'Ещё вопрос', 'meta' => []]);

            return new TurnCommand($command->conversationId, $message->id, $message->text, null, 'manager-next', 1);
        });
        $second = $this->turn($context, $request, $next);
        self::assertSame('suppressed', $second['status']);
        self::assertSame(2, $second['version']);
        self::assertSame($first, $this->turn($context, $request, $command));
        $changed = new TurnCommand($command->conversationId, $command->inputMessageId, 'Подмена', null, $command->idempotencyKey, 0);
        self::assertSame(['status' => 'unavailable'], $this->turn($context, $request, $changed));
        $other = app(TenantContextRunner::class)->run($context, fn () => Brand::create(['name' => 'Other', 'timezone' => 'Europe/Moscow']));
        $otherContext = new TenantContext($context->organizationId, $other->id, $context->actorId, $context->membershipId);
        $foreign = app(TenantContextRunner::class)->run($otherContext, function (): TurnCommand {
            $conversation = Conversation::create(['state' => [], 'mode' => 'manager']);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Чужой бренд', 'meta' => []]);

            return new TurnCommand($conversation->id, $message->id, $message->text, null, 'foreign', 0);
        });
        self::assertSame(['status' => 'unavailable'], $this->turn($context, $request, $foreign));
        $suggest = app(TenantContextRunner::class)->run($context, function (): TurnCommand {
            $conversation = Conversation::create(['state' => [], 'mode' => 'suggest']);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Вопрос', 'meta' => []]);

            return new TurnCommand($conversation->id, $message->id, $message->text, null, 'suggest', 0);
        });
        $suggested = $this->turn($context, $request, $suggest);
        self::assertSame('suggested', $suggested['status']);
        self::assertSame(1, $suggested['result']['version']);
        self::assertNull($suggested['result']['message_id']);
        self::assertNotEmpty($suggested['result']['suggestion']);
        $outcome = json_decode(DB::table('seller_turns')->where('conversation_id', $suggest->conversationId)->value('outcome'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('suggested', $outcome['status']);
        self::assertSame($suggested['result']['suggestion'], $outcome['messages'][0]['text']);
        self::assertSame(0, DB::table('messages')->where('conversation_id', $suggest->conversationId)->where('role', 'agent')->count());
        self::assertSame($suggested, $this->turn($context, $request, $suggest));
        $request->attributes->set('tenant.brand_revision', 'stale');
        self::assertSame(['status' => 'unavailable'], $this->turn($context, $request, $command));
        self::assertSame(0, $fake->calls);
        self::assertSame(2, DB::table('seller_turns')->where('status', 'suppressed')->count());
    }

    public function test_viewer_and_wrong_brand_do_not_start_ai(): void
    {
        [$context, $request, $command] = $this->fixture('bot', 'viewer');
        $fake = new RuntimeFakeDriver;
        app()->instance(FakeDriver::class, $fake);
        self::assertSame(['status' => 'unavailable'], $this->turn($context, $request, $command));
        $changed = new TurnCommand((string) Str::ulid(), $command->inputMessageId, $command->text, null, 'other', 0);
        self::assertSame(['status' => 'unavailable'], $this->turn($context, $request, $changed));
        self::assertSame(0, $fake->calls);
    }
}
