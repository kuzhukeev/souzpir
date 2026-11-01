<?php

namespace Tests\Feature\Seller\Engine;

use App\Ai\CanonicalJson;
use App\Ai\ContactMasker;
use App\Ai\Gateway;
use App\Ai\Protected\SellerExtractorStages;
use App\Ai\Protected\SellerResponderStages;
use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\ProtectedOperations\FirstTurnProtectedClaim;
use App\ProtectedOperations\FirstTurnState;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Engine\CreatePromise;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
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
use LogicException;
use Tests\TestCase;

final class CreatePromiseTest extends TestCase
{
    use DatabaseMigrations;

    public function createApplication()
    {
        $app = parent::createApplication();
        $expected = env('DB_TEST_DATABASE');
        $db = DB::selectOne('select current_database() as name, host(inet_server_addr()) as host, rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
        if (! $app->environment('testing') || ! is_string($expected) || ! str_contains($expected, '_test_')
            || config('database.connections.pgsql.database') !== $expected || $db->name !== $expected || env('APP_PREVIEW_DATABASE') === $expected
            || (! str_starts_with((string) $db->host, '127.') && $db->host !== '::1') || $db->rolsuper || $db->rolcreatedb || $db->rolcreaterole) {
            throw new \RuntimeException('An isolated test database is required.');
        }

        return $app;
    }

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

    public function test_create_promise_writes_task_and_state_once_from_verified_raw_calls(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('Raw source', ['protected_claim' => true]);
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, [
            'content' => null, 'toolCalls' => [
                ['id' => 'raw-call', 'name' => 'create_promise', 'arguments' => '{ "text" : "synthetic" }'],
            ],
        ]);
        $this->within($tenant, function () use ($http, $claim, $stage, $file): void {
            self::assertSame('completed', app(Gateway::class)->completeProtectedResponderWithRaw($http, $claim, $stage));
            self::assertSame('completed', app(Gateway::class)->completeProtectedResponderWithRaw($http, $claim, $stage));
            self::assertSame('raw-call', DB::transaction(fn () => app(Gateway::class)->readProtectedRawToolCallLocked($http, $claim, $stage, 0))['id']);
            $effect = app(CreatePromise::class);
            $first = $effect->execute($http, $claim, $stage, 0, 'promise-effect');
            self::assertSame($first, $effect->execute($http, $claim, $stage, 0, 'promise-effect'));
            self::assertSame(1, DB::table('seller_tasks')->count());
            $task = DB::table('seller_tasks')->where('id', $first['task_id'])->first();
            self::assertSame('promise', $task->type);
            self::assertSame($claim->conversationId, $task->conversation_id);
            self::assertSame('synthetic', json_decode($task->payload, true, 512, JSON_THROW_ON_ERROR)['text']);
            $state = json_decode(DB::table('conversations')->where('id', $claim->conversationId)->value('state'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('synthetic', $state['promises'][0]['text']);
            self::assertCount(1, $state['promises']);
            self::assertSame(1, DB::table('audit_logs')->where('action', 'seller.promise.created')->count());
            try {
                $effect->execute($http, $claim, $stage, 1, 'promise-effect');
                self::fail('Another action body reused the effect key.');
            } catch (IdempotencyConflict) {
                self::assertSame(1, DB::table('seller_tasks')->count());
            }
            unlink($file);
        });
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_create_promise_rejects_other_brand_stale_turn_and_invalid_index(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('Promise freshness', ['protected_claim' => true]);
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, [
            'content' => null, 'toolCalls' => [['id' => 'promise-one', 'name' => 'create_promise', 'arguments' => '{"text":"Уточню доставку"}']],
        ]);
        $this->within($tenant, function () use ($tenant, $http, $claim, $stage, $file): void {
            self::assertSame('completed', app(Gateway::class)->completeProtectedResponderWithRaw($http, $claim, $stage));
            unlink($file);
            $effect = app(CreatePromise::class);
            try {
                $effect->execute($http, $claim, $stage, -1, 'bad-index');
                self::fail('Invalid index was accepted.');
            } catch (LogicException) {
                self::assertSame(0, DB::table('seller_tasks')->count());
            }
            $other = $this->within(new TenantContext($tenant->organizationId, actorId: $tenant->actorId),
                fn () => Brand::create(['name' => 'Другой бренд']));
            $foreign = new TenantContext($tenant->organizationId, $other->id, $tenant->actorId, $tenant->membershipId);
            try {
                $this->within($foreign, fn () => $effect->execute($http, $claim, $stage, 0, 'foreign'));
                self::fail('Other brand executed the promise.');
            } catch (LogicException) {
                self::assertSame(0, DB::table('seller_tasks')->count());
            }
            $forged = new TurnClaim('claimed', $claim->turnId, $claim->conversationId, $claim->organizationId,
                $claim->brandId, $claim->baseVersion, str_repeat('0', 64));
            try {
                $effect->execute($http, $forged, $stage, 0, 'forged');
                self::fail('Forged claim executed the promise.');
            } catch (LogicException) {
                self::assertSame(0, DB::table('seller_tasks')->count());
            }
            Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Поздняя реплика', 'meta' => []]);
            try {
                $effect->execute($http, $claim, $stage, 0, 'stale');
                self::fail('Stale turn executed the promise.');
            } catch (LogicException) {
                self::assertSame(0, DB::table('seller_tasks')->count());
            }
        });
    }

    public function test_create_promise_rechecks_session_before_writing(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('Promise rights', ['protected_claim' => true]);
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, [
            'content' => null, 'toolCalls' => [['id' => 'promise-rights', 'name' => 'create_promise', 'arguments' => '{"text":"Уточню детали"}']],
        ]);
        $this->within($tenant, function () use ($http, $claim, $stage, $file): void {
            self::assertSame('completed', app(Gateway::class)->completeProtectedResponderWithRaw($http, $claim, $stage));
            app(AuthSessionRegistry::class)->withinActive($http, fn () => app(AuthSessionRegistry::class)->revokeCurrent($http));
            try {
                app(CreatePromise::class)->execute($http, $claim, $stage, 0, 'promise-rights');
                self::fail('Revoked session executed a promise.');
            } catch (LogicException) {
                self::assertSame(0, DB::table('seller_tasks')->count());
            }
            unlink($file);
        });
    }

    public function test_create_promise_rechecks_seller_module_right(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('Promise module', ['protected_claim' => true]);
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, [
            'content' => null, 'toolCalls' => [['id' => 'promise-module', 'name' => 'create_promise', 'arguments' => '{"text":"Уточню детали"}']],
        ]);
        $this->within($tenant, function () use ($tenant, $http, $claim, $stage, $file): void {
            self::assertSame('completed', app(Gateway::class)->completeProtectedResponderWithRaw($http, $claim, $stage));
            DB::table('brand_modules')->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->where('module', 'seller')->update(['enabled' => false]);
            try {
                app(CreatePromise::class)->execute($http, $claim, $stage, 0, 'promise-module');
                self::fail('Disabled seller module executed a promise.');
            } catch (LogicException) {
                self::assertSame(0, DB::table('seller_tasks')->count());
            }
            unlink($file);
        });
    }

    private function responderFixture(TenantContext $tenant, Request $http, TurnClaim $claim, string $extractor, array $result = []): array
    {
        return $this->within($tenant, function () use ($http, $claim, $extractor, $result): array {
            $stateId = app(FirstTurnState::class)->prepare($http, $claim, $extractor);
            $stage = app(SellerResponderStages::class)->prepare($http, $claim, $extractor, $stateId);
            [$request, $context] = app(SellerResponderStages::class)->load($http, $claim, $stage);
            $directory = storage_path('app/responder-fixtures-'.Str::random(12));
            mkdir($directory, 0700, true);
            config(['features.E0-05.defaults.fixture_directory' => $directory]);
            $file = $directory.DIRECTORY_SEPARATOR.CanonicalJson::hash(app(ContactMasker::class)->request($request->toArray())).'.json';
            $fake = array_replace(['content' => '«Антон: Тестовый ответ»', 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
                'model' => $request->model, 'latencyMs' => 1, 'costMicros' => 0], $result);
            file_put_contents($file, json_encode(['result' => $fake], JSON_THROW_ON_ERROR));

            return [$stage, $request, $context, $file, $stateId];
        });
    }

    private function fixture(string $text = 'Synthetic question', array $options = []): array
    {
        app(PromptSetsSeeder::class)->run();
        $user = User::create(['name' => 'Synthetic', 'email' => 'first-state-'.Str::lower(Str::random(12)).'@example.test', 'password' => 'synthetic-test-password']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->refresh();
        $org = Organization::create(['name' => 'Synthetic', 'owner_user_id' => $user->id]);
        [$member, $brand] = $this->within(new TenantContext($org->id, actorId: $user->id), fn () => [
            Membership::create(['user_id' => $user->id, 'role' => 'owner']), Brand::create(['name' => 'Synthetic brand', 'timezone' => 'Europe/Moscow']),
        ]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, $user->id, $member->id);
        $this->within($tenant, fn () => app(YamlKnowledgeImporter::class)->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data')));
        $session = new Store('c02-state-session', new ArraySessionHandler(120));
        $session->setId(Str::random(40));
        $session->start();
        $session->put('auth.version', $user->auth_version);
        $http = Request::create('/', 'POST');
        $http->setLaravelSession($session);
        $http->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($http, $user, $user->auth_version);
        app(AuthSessionRegistry::class)->withinActive($http, fn () => app(SessionBrandSelection::class)->initialize($http, $org->id, $brand->id));
        [$conversation, $input] = $this->within($tenant, function () use ($text, $options): array {
            $conversation = Conversation::create(['state' => $options['state'] ?? [], 'mode' => $options['mode'] ?? 'bot']);
            if ($options['history'] ?? false) {
                Message::create(['conversation_id' => $conversation->id, 'role' => 'agent', 'text' => 'Earlier synthetic output', 'meta' => []]);
            }
            if ($options['changed'] ?? false) {
                $conversation->update(['state' => ['temporary' => true]]);
                $conversation->update(['state' => []]);
            }
            $input = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => $text, 'meta' => []]);

            return [$conversation, $input];
        });
        $command = new TurnCommand($conversation->id, $input->id, $input->text, null, 'state-stage-'.Str::random(16), 0);
        $claim = ($options['protected_claim'] ?? true)
            ? $this->within($tenant, fn () => app(FirstTurnProtectedClaim::class)->claim($http, $command))
            : app(ConversationRepository::class)->claimTurn($tenant, $command);
        $stage = $this->within($tenant, fn () => app(SellerExtractorStages::class)->prepare($http, $claim));
        [$request] = $this->within($tenant, fn () => app(SellerExtractorStages::class)->load($http, $claim, $stage));
        $directory = storage_path('app/state-fixtures-'.Str::random(12));
        mkdir($directory, 0700, true);
        config(['features.E0-05.defaults.fixture_directory' => $directory]);
        $file = $directory.DIRECTORY_SEPARATOR.CanonicalJson::hash(app(ContactMasker::class)->request($request->toArray())).'.json';
        $content = ($options['invalid'] ?? false) ? 'not-json' : ($options['content'] ?? '{"client_intent":"answer_only","slot_updates":{"city":"Москва","email":"[EMAIL]"}}');
        file_put_contents($file, json_encode(['result' => ['content' => $content, 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
            'model' => $request->model, 'latencyMs' => 1, 'costMicros' => array_key_exists('cost', $options) ? $options['cost'] : 0]], JSON_THROW_ON_ERROR));
        $status = $this->within($tenant, fn () => app(Gateway::class)->completeProtectedExtractor($http, $claim, $stage));
        self::assertSame(array_key_exists('cost', $options) && $options['cost'] === null ? 'uncertain' : 'completed', $status);
        unlink($file);

        return [$tenant, $http, $claim, $stage];
    }

    private function within(TenantContext $tenant, \Closure $action): mixed
    {
        return app(TenantContextRunner::class)->run($tenant, $action);
    }
}
