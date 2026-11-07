<?php

namespace Tests\Feature\Seller\Settings\Schedule;

use App\Ai\Drivers\Driver;
use App\Ai\Drivers\FakeDriver;
use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Channels\Core\InboundRecorder;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Seller\Runtime\BrandRuntimeSchedule;
use App\Seller\Runtime\FirstInboundRuntime;
use App\Seller\Settings\Schedule\AssignmentCandidates;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Carbon\CarbonImmutable;
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

final class ScheduleFakeDriverE606B implements Driver
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

final class ScheduleRuntimeE606BTest extends TestCase
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

    private function fixture(string $mode = 'bot', string $role = 'owner', ?string $channel = null): array
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
        [$conversation, $message] = app(TenantContextRunner::class)->run($ownerContext, function () use ($ownerContext, $mode, $channel): array {
            app(YamlKnowledgeImporter::class)->importAndPublish($ownerContext, base_path('tests/Feature/Seller/Knowledge/Fixtures/data'));
            $channelAccountId = null;
            if ($channel !== null) {
                $channelAccountId = (string) Str::ulid();
                DB::table('channel_accounts')->insert(['id' => $channelAccountId, 'organization_id' => $ownerContext->organizationId, 'brand_id' => $ownerContext->brandId,
                    'type' => $channel, 'created_at' => now(), 'updated_at' => now()]);
            }
            $conversation = Conversation::create(['state' => [], 'mode' => $mode, 'channel_account_id' => $channelAccountId]);
            $message = $channelAccountId !== null
                ? app(InboundRecorder::class)->ingest($ownerContext, $channelAccountId, 'synthetic-event', [], $conversation->id, 'Вопрос от synthetic-contact@example.test')
                : Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Вопрос от synthetic-contact@example.test', 'meta' => []]);

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
        $command = new TurnCommand($conversation->id, $message->id, $message->text, $message->external_event_id, 'runtime-test-key', 0);

        return [$context, $request, $command];
    }

    private function turn(TenantContext $tenant, #[\SensitiveParameter] Request $request, #[\SensitiveParameter] TurnCommand $command): array
    {
        return app(TenantContextRunner::class)->run($tenant, fn () => app(FirstInboundRuntime::class)->turn($request, $command));
    }

    public function test_outside_window_and_holidays_suppress_bot_without_ai(): void
    {
        [$tenant, $request, $command] = $this->fixture();
        $week = array_fill(0, 7, ['enabled' => true, 'start' => '09:00', 'end' => '18:00', 'behavior' => 'bot']);
        DB::table('seller_schedule_preferences')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'payload' => json_encode(['week' => $week, 'holidays' => ['2026-11-03']]), 'created_at' => now(), 'updated_at' => now()]);
        $fake = new ScheduleFakeDriverE606B;
        app()->instance(FakeDriver::class, $fake);
        CarbonImmutable::setTestNow('2026-11-02 17:00:00+00');
        $result = $this->turn($tenant, $request, $command);
        self::assertSame('suppressed', $result['status']);
        self::assertSame(0, $fake->calls);
        self::assertSame(1, DB::table('messages')->where('conversation_id', $command->conversationId)->where('role', 'agent')->count());
        self::assertSame('Передаю менеджеру, он напишет сегодня до конца дня.', DB::table('messages')->where('conversation_id', $command->conversationId)->where('role', 'agent')->value('text'));
        self::assertSame($result, $this->turn($tenant, $request, $command));
        self::assertSame(1, DB::table('messages')->where('conversation_id', $command->conversationId)->where('role', 'agent')->count());
        CarbonImmutable::setTestNow('2026-11-03 09:00:00+00');
        self::assertFalse(app(BrandRuntimeSchedule::class)->available($tenant, app(BrandRuntimeSchedule::class)->settings($tenant), CarbonImmutable::now()));
        CarbonImmutable::setTestNow();
    }

    public function test_channel_override_lets_telegram_bypass_module_off(): void
    {
        [$tenant, $request, $command] = $this->fixture(channel: 'telegram');
        DB::table('seller_schedule_preferences')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
            'payload' => json_encode(['mode' => 'off', 'channel_overrides' => ['telegram' => 'autonomous']]), 'created_at' => now(), 'updated_at' => now()]);
        $fake = new ScheduleFakeDriverE606B;
        app()->instance(FakeDriver::class, $fake);
        $result = $this->turn($tenant, $request, $command);
        self::assertSame('completed', $result['status']);
        self::assertSame(2, $fake->calls);
    }

    public function test_manager_assignment_uses_only_eligible_brand_members_and_empty_pool_is_safe(): void
    {
        [$tenant, $request, $command] = $this->fixture('manager');
        $settings = ['assignment' => ['method' => 'fixed', 'fixed' => $tenant->actorId, 'members' => [['id' => $tenant->actorId, 'enabled' => true, 'max_active' => null]]]];
        DB::table('seller_schedule_preferences')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'payload' => json_encode($settings), 'created_at' => now(), 'updated_at' => now()]);
        self::assertSame('suppressed', $this->turn($tenant, $request, $command)['status']);
        self::assertSame($tenant->actorId, DB::table('conversations')->where('id', $command->conversationId)->value('assigned_user_id'));
        $service = app(AssignmentCandidates::class);
        self::assertNull($service->select($tenant, ['method' => 'fixed', 'fixed' => (string) Str::ulid(), 'members' => []]));
        [$viewer, $viewerRequest, $viewerCommand] = $this->fixture('bot', 'viewer');
        self::assertSame('unavailable', $this->turn($viewer, $viewerRequest, $viewerCommand)['status']);
    }

    public function test_inside_window_runs_the_protected_first_turn(): void
    {
        [$tenant, $request, $command] = $this->fixture();
        $week = array_fill(0, 7, ['enabled' => true, 'start' => '09:00', 'end' => '18:00', 'behavior' => 'bot']);
        DB::table('seller_schedule_preferences')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'payload' => json_encode(['week' => $week]), 'created_at' => now(), 'updated_at' => now()]);
        CarbonImmutable::setTestNow('2026-11-02 09:00:00+00');
        $fake = new ScheduleFakeDriverE606B;
        app()->instance(FakeDriver::class, $fake);
        $result = $this->turn($tenant, $request, $command);
        self::assertSame('completed', $result['status']);
        self::assertSame(2, $fake->calls);
        CarbonImmutable::setTestNow();
    }

    public function test_foreign_brand_candidate_is_not_assigned(): void
    {
        [$tenant, $request, $command] = $this->fixture('manager');
        [$foreign] = $this->fixture();
        $assignment = ['method' => 'fixed', 'fixed' => $foreign->actorId, 'members' => [['id' => $foreign->actorId, 'enabled' => true, 'max_active' => null]]];
        DB::table('seller_schedule_preferences')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'payload' => json_encode(['assignment' => $assignment]), 'created_at' => now(), 'updated_at' => now()]);
        self::assertSame('suppressed', $this->turn($tenant, $request, $command)['status']);
        self::assertNull(DB::table('conversations')->where('id', $command->conversationId)->value('assigned_user_id'));
    }
}
