<?php

namespace Tests\Feature\Seller\Runtime;

use App\Ai\Drivers\Driver;
use App\Ai\Drivers\FakeDriver;
use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Dialogs\DialogReader;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
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
use Tests\Feature\Seller\Knowledge\Fixtures\PrototypeKnowledge;
use Tests\TestCase;

/** Scripted driver: first call answers the extractor stage (JSON), the rest answer the responder stage (plain text). */
final class PublishedFieldScriptedDriver implements Driver
{
    public int $calls = 0;

    public function __construct(private readonly string $extractionJson) {}

    public function complete(#[\SensitiveParameter] array $body): array
    {
        $content = $this->calls++ === 0 ? $this->extractionJson : 'Записал, продолжаем.';

        return ['content' => $content, 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
            'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
    }
}

/** D-5.1: the real inbound runtime (not the draft sandbox) extracts published brand fields into LeadState. */
final class PublishedFieldsFirstInboundE610BTest extends TestCase
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

    /** @return array{0:TenantContext,1:Request,2:TurnCommand} */
    private function fixture(array $clientFields, string $clientMessage): array
    {
        app(PromptSetsSeeder::class)->run();
        $owner = User::create(['name' => 'Synthetic', 'email' => Str::random(12).'@example.test', 'password' => 'synthetic-test-password']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $owner->refresh();
        $org = Organization::create(['name' => 'Synthetic', 'owner_user_id' => $owner->id]);
        [$member, $brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), fn () => [
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']), Brand::create(['name' => 'Автосервис', 'timezone' => 'Europe/Moscow']),
        ]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $ownerContext = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        [$conversation, $message] = app(TenantContextRunner::class)->run($ownerContext, function () use ($ownerContext, $clientFields, $clientMessage): array {
            $prototype = new PrototypeKnowledge(base_path('tests/Feature/Seller/Knowledge/Fixtures/data'), 0);
            $data = [];
            foreach (PrototypeKnowledge::FILES as $file) {
                $data[$file] = $prototype->all($file);
            }
            // Непрототипный бренд (не "Союз Пир") — Extractor/Policy не собирают оптовые слоты (D-5.1).
            $data['persona']['company'] = 'Автосервис';
            $data['policy']['client_fields'] = $clientFields;
            $repo = app(KnowledgeRepository::class);
            $draft = $repo->createDraft($ownerContext, $data);
            $repo->publish($ownerContext, $draft['id'], 1);
            $conversation = Conversation::create(['state' => [], 'mode' => 'bot']);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => $clientMessage, 'meta' => []]);

            return [$conversation, $message];
        });
        $session = new Store('runtime-test', new ArraySessionHandler(120));
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
        $command = new TurnCommand($conversation->id, $message->id, $message->text, null, 'runtime-test-key', 0);

        return [$ownerContext, $request, $command];
    }

    private function turn(TenantContext $tenant, #[\SensitiveParameter] Request $request, #[\SensitiveParameter] TurnCommand $command): array
    {
        return app(TenantContextRunner::class)->run($tenant, fn () => app(FirstInboundRuntime::class)->turn($request, $command));
    }

    public function test_real_first_turn_stores_published_field_from_a_non_wholesale_brand(): void
    {
        $fields = [
            ['id' => 'car_model', 'name' => 'Марка и модель', 'type' => 'text', 'options' => [], 'required' => true, 'stage' => 'need'],
            ['id' => 'service_level', 'name' => 'Уровень обслуживания', 'type' => 'select', 'options' => ['basic', 'premium'], 'required' => true, 'stage' => 'need'],
        ];
        [$context, $request, $command] = $this->fixture($fields, 'У меня Toyota Camry, нужен базовый уровень');
        $extraction = json_encode(['client_intent' => 'unclear', 'slot_updates' => ['car_model' => 'Toyota Camry', 'service_level' => 'basic']], JSON_THROW_ON_ERROR);
        app()->instance(FakeDriver::class, new PublishedFieldScriptedDriver($extraction));

        $result = $this->turn($context, $request, $command);

        self::assertSame('completed', $result['status']);
        $state = json_decode(DB::table('conversations')->where('id', $command->conversationId)->value('state'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Toyota Camry', $state['slots']['car_model']);
        self::assertSame('basic', $state['slots']['service_level']);
        // Контактные/оптовые слоты прототип-бренда не собираются для брендов, отличных от "Союз Пир" (D-5.1).
        self::assertNull($state['slots']['city']);

        $reader = app(DialogReader::class);
        $read = app(TenantContextRunner::class)->run($context, fn () => $reader->read($context, $context->actorId, false, true, $command->conversationId, 'all', '', 'newest', [], $fields));
        $slots = collect($read['dialog']['slots'])->keyBy('key');
        self::assertSame('Toyota Camry', $slots['car_model']['value']);
        self::assertSame('Марка и модель', $slots['car_model']['label']);
        self::assertSame('basic', $slots['service_level']['value']);
        self::assertSame('Уровень обслуживания', $slots['service_level']['label']);
    }

    public function test_real_first_turn_rejects_an_out_of_enum_published_field_value(): void
    {
        $fields = [
            ['id' => 'service_level', 'name' => 'Уровень обслуживания', 'type' => 'select', 'options' => ['basic', 'premium'], 'required' => true, 'stage' => 'need'],
        ];
        [$context, $request, $command] = $this->fixture($fields, 'Хочу люкс-уровень');
        // "люкс" не входит в опубликованный список вариантов — значение не должно пройти схему extractor'а.
        $extraction = json_encode(['client_intent' => 'unclear', 'slot_updates' => ['service_level' => 'люкс']], JSON_THROW_ON_ERROR);
        app()->instance(FakeDriver::class, new PublishedFieldScriptedDriver($extraction));

        $result = $this->turn($context, $request, $command);

        // Как и в sandbox-пути (PublishedFieldsSandboxReplyE610ETest), первый ход с невалидным значением
        // опубликованного select-поля не завершается черновым ответом — extractor отбрасывает его целиком.
        self::assertSame('unavailable', $result['status']);
        $state = json_decode(DB::table('conversations')->where('id', $command->conversationId)->value('state'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $state);
    }
}
