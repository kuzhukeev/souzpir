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
use Tests\TestCase;

final class QuotaRuntimeFakeDriverE113 implements Driver
{
    public int $calls = 0;

    public function complete(#[\SensitiveParameter] array $body): array
    {
        $content = $this->calls++ === 0 ? '{"client_intent":"opt_out","do_not_disturb":true}' : 'Хорошо, больше не будем писать.';

        return ['content' => $content, 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
            'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
    }
}

final class QuotaRuntimeE113Test extends TestCase
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

    public function test_exhausted_new_dialog_goes_to_manager(): void
    {
        [$user, $brand, $input, $org] = $this->fixture();
        $plan = DB::table('plans')->where('code', 'start')->value('id');
        DB::table('plan_limits')->where('plan_id', $plan)->where('key', 'ai.units_month')->update(['value' => 0]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        app()->instance(FakeDriver::class, new QuotaRuntimeFakeDriverE113);
        $this->login($user, $brand);
        $this->postJson('/seller/turns/runtime', [...$input, 'expected_version' => 0])->assertOk()->assertJsonPath('data.status', 'suppressed');
        $this->assertDatabaseHas('conversations', ['id' => $input['conversation_id'], 'mode' => 'manager', 'stage' => 'handoff', 'outcome' => 'handoff']);
        $this->assertDatabaseHas('messages', ['conversation_id' => $input['conversation_id'], 'role' => 'agent', 'text' => 'Передаю менеджеру, он напишет сегодня до конца дня.']);
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    private function fixture(): array
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
        [$conversation, $message] = app(TenantContextRunner::class)->run($tenant, function () use ($tenant): array {
            app(YamlKnowledgeImporter::class)->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data'));
            $conversation = Conversation::create(['state' => [], 'mode' => 'bot']);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Private synthetic first input', 'meta' => []]);

            return [$conversation, $message];
        });

        return [$user, $brand, ['conversation_id' => $conversation->id, 'message_id' => $message->id, 'text' => $message->text, 'idempotency_key' => 'first-turn-test'], $org];
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
