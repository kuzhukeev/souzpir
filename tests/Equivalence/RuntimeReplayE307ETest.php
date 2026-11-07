<?php

namespace Tests\Equivalence;

use App\Ai\Drivers\Driver;
use App\Ai\Drivers\FakeDriver;
use App\Ai\Drivers\Rejected;
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
use Tests\Feature\Equivalence\Support\RecordedGateway;
use Tests\TestCase;

final class RuntimeReplayE307ETest extends TestCase
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

    public function test_recorded_request_is_rejected_by_actual_runtime_without_network(): void
    {
        [$context, $request, $command] = $this->fixture();
        $root = base_path('tests/fixtures/prototype/recorded-v2');
        $manifest = json_decode(file_get_contents($root.'/manifest.json'), true, 128, JSON_THROW_ON_ERROR);
        $case = json_decode(file_get_contents($root.'/'.$manifest['cases'][0]['fixture']), true, 128, JSON_THROW_ON_ERROR);
        self::assertSame('persona.newbie_voronezh', $case['case_id']);
        $probe = new class($root, $case) implements Driver
        {
            public int $calls = 0;

            public array $keys = [];

            public ?string $rejection = null;

            private RecordedGateway $recorded;

            public function __construct(string $root, array $case)
            {
                $this->recorded = new RecordedGateway($root, $case);
            }

            public function complete(#[\SensitiveParameter] array $body): array
            {
                $this->calls++;
                $this->keys = array_keys($body);
                try {
                    return $this->recorded->complete($body);
                } catch (Rejected $error) {
                    $this->rejection = $error->getMessage();
                    throw $error;
                }
            }
        };
        app()->instance(FakeDriver::class, $probe);
        $result = $this->turn($context, $request, $command);
        self::assertSame(1, $probe->calls);
        self::assertContains('model', $probe->keys);
        self::assertSame('Recorded request does not match.', $probe->rejection);
        $record = json_decode(file_get_contents($root.'/'.$case['llm_calls'][0]['path']), true, 128, JSON_THROW_ON_ERROR);
        self::assertSame(['messages', 'options'], array_keys($record['request']));
        self::assertNotSame('completed', $result['status']);
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_recorded_first_client_inputs_expose_runtime_request_mismatches_offline(): void
    {
        $root = base_path('tests/fixtures/prototype/recorded-v2');
        $manifest = json_decode(file_get_contents($root.'/manifest.json'), true, 128, JSON_THROW_ON_ERROR);
        $counts = ['request_mismatch' => 0, 'other' => 0, 'scheduler' => 0];
        $details = [];
        [$context, $request] = $this->fixture();
        foreach ($manifest['cases'] as $item) {
            self::assertSame($item['fixture_sha256'], hash_file('sha256', $root.'/'.$item['fixture']));
            $case = json_decode(file_get_contents($root.'/'.$item['fixture']), true, 128, JSON_THROW_ON_ERROR);
            $turns = $case['trace']['turns'] ?? [];
            $client = null;
            foreach ($turns as $turn) {
                if (is_string($turn['client'] ?? null) && trim($turn['client']) !== '') {
                    $client = $turn['client'];
                    break;
                }
            }
            if ($client === null) {
                $counts['scheduler']++;
                $details[$case['case_id']] = 'scheduler_without_client_turn';

                continue;
            }
            $extractor = null;
            foreach ($case['llm_calls'] as $entry) {
                $record = json_decode(file_get_contents($root.'/'.$entry['path']), true, 128, JSON_THROW_ON_ERROR);
                if (($record['request']['options']['component'] ?? null) === 'extractor') {
                    $extractor = $entry;
                    break;
                }
            }
            if ($extractor === null) {
                $counts['other']++;
                $details[$case['case_id']] = 'no_recorded_extractor';

                continue;
            }
            $selected = $case;
            $selected['llm_calls'] = [$extractor];
            $state = $case['execution']['scenario']['initial'] ?? [];
            [$conversation, $message] = app(TenantContextRunner::class)->run($context, function () use ($state, $client): array {
                $conversation = Conversation::create(['state' => $state, 'mode' => 'bot']);
                $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => $client, 'meta' => []]);

                return [$conversation, $message];
            });
            $command = new TurnCommand($conversation->id, $message->id, $message->text, null, 'replay-'.$case['case_id'], 0);
            $probe = new class($root, $selected) implements Driver
            {
                public int $calls = 0;

                public ?string $rejection = null;

                private RecordedGateway $recorded;

                public function __construct(string $root, array $case)
                {
                    $this->recorded = new RecordedGateway($root, $case);
                }

                public function complete(#[\SensitiveParameter] array $body): array
                {
                    $this->calls++;
                    try {
                        return $this->recorded->complete($body);
                    } catch (Rejected $error) {
                        $this->rejection = $error->getMessage();
                        throw $error;
                    }
                }
            };
            app()->instance(FakeDriver::class, $probe);
            $result = $this->turn($context, $request, $command);
            $label = $probe->rejection === 'Recorded request does not match.' ? 'request_mismatch' : 'other';
            $counts[$label]++;
            $details[$case['case_id']] = ['category' => $label, 'calls' => $probe->calls, 'status' => $result['status'] ?? null];
        }
        self::assertCount(41, $details);
        self::assertSame(2, $counts['scheduler']);
        self::assertSame(39, $counts['request_mismatch'] + $counts['other']);
        self::assertSame(['category' => 'other', 'calls' => 0, 'status' => 'unavailable'], $details['manual.no_repeat_filled_slot']);
        fwrite(STDERR, 'REPLAY_DIAGNOSTIC '.json_encode(['counts' => $counts, 'cases' => $details], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_runtime_rejects_viewer_and_another_brand_before_ai(): void
    {
        $probe = new class implements Driver
        {
            public int $calls = 0;

            public function complete(#[\SensitiveParameter] array $body): array
            {
                $this->calls++;
                throw new Rejected('Unexpected provider call.');
            }
        };
        app()->instance(FakeDriver::class, $probe);
        [$viewerContext, $viewerRequest, $viewerCommand] = $this->fixture(role: 'viewer');
        self::assertSame('unavailable', $this->turn($viewerContext, $viewerRequest, $viewerCommand)['status']);
        [$ownerContext, $ownerRequest] = $this->fixture();
        self::assertSame('unavailable', $this->turn($ownerContext, $ownerRequest, $viewerCommand)['status']);
        self::assertSame(0, $probe->calls);
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }
}
