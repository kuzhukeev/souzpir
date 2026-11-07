<?php

namespace Tests\Feature\Seller\Runtime;

use App\Ai\Drivers\Driver;
use App\Ai\Drivers\FakeDriver;
use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Ai\PromptSetsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class ServicePhraseOverrideFakeDriverE601E implements Driver
{
    public int $calls = 0;

    public function __construct(private readonly string $extractorContent, private readonly string $responderContent) {}

    public function complete(#[\SensitiveParameter] array $body): array
    {
        $content = $this->calls++ === 0 ? $this->extractorContent : $this->responderContent;

        return ['content' => $content, 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
            'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
    }
}

/** SP-E6-01E: a saved service phrase must replace the fake responder text for the matching runtime event. */
final class ServicePhraseOverrideE601ETest extends TestCase
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

    public function test_unknown_fact_reply_uses_the_saved_no_answer_phrase(): void
    {
        [$user, $brand, $input] = $this->fixture(['no_answer' => 'Передам вопрос коллегам и отвечу вам отдельно.']);
        app()->instance(FakeDriver::class, new ServicePhraseOverrideFakeDriverE601E(
            '{"client_intent":"answer_only","unknown_question":"Сколько машин в автопарке?"}',
            'По этой позиции наличие прямо сейчас не скажу — уточню у коллег на складе и напишу вам сегодня.',
        ));
        $this->login($user, $brand);
        $result = $this->postJson('/seller/turns/first', $input)->assertOk()->json('data.result');
        self::assertSame('Передам вопрос коллегам и отвечу вам отдельно.', DB::table('messages')->where('id', $result['message_id'])->value('text'));
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_handoff_reply_uses_the_saved_handoff_phrase(): void
    {
        [$user, $brand, $input] = $this->fixture(['handoff' => 'Подключаю менеджера, он свяжется с вами сегодня.']);
        app()->instance(FakeDriver::class, new ServicePhraseOverrideFakeDriverE601E(
            '{"client_intent":"want_human"}',
            'Передаю менеджеру, он напишет вам.',
        ));
        $this->login($user, $brand);
        $result = $this->postJson('/seller/turns/first', $input)->assertOk()->json('data.result');
        self::assertSame('Подключаю менеджера, он свяжется с вами сегодня.', DB::table('messages')->where('id', $result['message_id'])->value('text'));
        $conversation = DB::table('conversations')->where('id', $input['conversation_id'])->first();
        self::assertSame('manager', $conversation->mode);
        self::assertSame('handoff', $conversation->stage);
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    private function fixture(array $servicePhrases): array
    {
        app(PromptSetsSeeder::class)->run();
        $user = User::create(['name' => 'Synthetic', 'email' => Str::random(12).'@example.test', 'password' => 'synthetic-test-password']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->refresh();
        $org = Organization::create(['name' => 'Synthetic', 'owner_user_id' => $user->id]);
        [$member, $brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id), fn () => [
            Membership::create(['user_id' => $user->id, 'role' => 'owner']), Brand::create(['name' => 'Synthetic brand', 'timezone' => 'Europe/Moscow']),
        ]);
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, $user->id, $member->id);
        [$conversation, $message] = app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $servicePhrases): array {
            $data = [];
            foreach (KnowledgeSnapshot::FILES as $file) {
                $path = base_path('tests/Feature/Seller/Knowledge/Fixtures/data/'.$file.'.yaml');
                $data[$file] = Yaml::parse(file_get_contents($path));
            }
            $data['persona']['service_phrases'] = $servicePhrases;
            app(YamlKnowledgeImporter::class)->publishData($tenant, $data);
            $conversation = Conversation::create(['state' => [], 'mode' => 'bot']);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Synthetic first input', 'meta' => []]);

            return [$conversation, $message];
        });

        return [$user, $brand, ['conversation_id' => $conversation->id, 'message_id' => $message->id, 'text' => $message->text, 'idempotency_key' => 'service-phrase-'.Str::random(16)]];
    }

    private function login(User $user, Brand $brand): void
    {
        $this->actingAs($user);
        $this->startSession();
        session()->invalidate();
        $this->withSession(['auth.version' => $user->auth_version, 'brand_id' => $brand->id]);
        $request = Request::create('/', 'GET');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
    }
}
