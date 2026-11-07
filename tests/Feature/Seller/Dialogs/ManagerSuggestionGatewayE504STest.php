<?php

namespace Tests\Feature\Seller\Dialogs;

use App\Ai\CanonicalJson;
use App\Ai\ContactMasker;
use App\Ai\Gateway;
use App\Contracts\Ai\AiContext;
use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Dialogs\ManagerSuggestionExtractorInput;
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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ManagerSuggestionGatewayE504STest extends TestCase
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

    public function test_fake_gateway_accepts_only_the_server_source_and_replays(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        config(['runtime.ai_driver' => 'fake']);
        app(PromptSetsSeeder::class)->run();
        $owner = User::create(['name' => 'Test', 'email' => Str::random(12).'@example.test', 'password' => 'synthetic-test-password']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $owner->refresh();
        $org = Organization::create(['name' => 'Test', 'owner_user_id' => $owner->id]);
        [$member, $brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), fn () => [Membership::create(['user_id' => $owner->id, 'role' => 'owner']), Brand::create(['name' => 'Test'])]);
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $dialog = app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $owner) {
            app(YamlKnowledgeImporter::class)->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data'));
            $dialog = Conversation::create(['state' => [], 'mode' => 'manager', 'assigned_user_id' => $owner->id]);
            Message::create(['conversation_id' => $dialog->id, 'role' => 'client', 'text' => 'Синтетический вопрос', 'meta' => []]);

            return $dialog;
        });
        $dir = base_path('.local/runtime/e504s-gateway');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        config(['features.E0-05.defaults.fixture_directory' => $dir]);
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->post('/test-manager-gateway/{dialog}', function (Request $request, string $dialog) use ($dir) {
            try {
                [$input] = app(ManagerSuggestionExtractorInput::class)->build($request, $dialog, 'fake-gateway-test');
            } catch (IdempotencyConflict) {
                return response()->json(['stale' => true], 409);
            }
            $body = app(ContactMasker::class)->request($input->toArray());
            $result = ['content' => '{"client_intent":"answer_only","slot_updates":{}}', 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $input->model, 'latencyMs' => 1, 'costMicros' => 0];
            file_put_contents($dir.'/'.CanonicalJson::hash($body).'.json', json_encode(['result' => $result], JSON_THROW_ON_ERROR));
            $value = app(Gateway::class)->completeManagerSuggestionExtractor($request, $dialog, 'fake-gateway-test');

            return response()->json(['call_id' => $value->aiCallId, 'content' => $value->content]);
        });
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->post('/test-manager-bypass/{dialog}', function (Request $request, string $dialog) {
            [$input] = app(ManagerSuggestionExtractorInput::class)->build($request, $dialog, 'fake-gateway-test');
            $tenant = app(TenantContext::class);
            $context = new AiContext($tenant->organizationId, $tenant->brandId, 'extractor', 'seller', 'conversation', $dialog,
                'manager-suggestion-extractor-'.str_repeat('a', 64), ['conversation_content', 'client_profile', 'knowledge_internal']);
            try {
                app(Gateway::class)->complete($input, $context);
            } catch (\LogicException) {
                return response()->json(['blocked' => true], 403);
            }

            return response()->json(['blocked' => false]);
        });
        $this->actingAs($owner);
        $this->startSession();
        session()->invalidate();
        $this->withSession(['auth.version' => $owner->auth_version, 'brand_id' => $brand->id]);
        $request = Request::create('/', 'GET');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $owner);
        app(AuthSessionRegistry::class)->issue($request, $owner, $owner->auth_version);
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        $url = '/test-manager-gateway/'.$dialog->id;
        $first = $this->postJson($url)->assertOk()->json();
        $this->postJson($url)->assertOk()->assertExactJson($first);
        self::assertNotEmpty($first['call_id']);
        self::assertSame(1, DB::table('ai_calls')->where('organization_id', $org->id)->where('brand_id', $brand->id)->count());
        $this->postJson('/test-manager-bypass/'.$dialog->id)->assertForbidden();
        app(TenantContextRunner::class)->run($tenant, fn () => Message::create(['conversation_id' => $dialog->id, 'role' => 'client', 'text' => 'Новый вопрос', 'meta' => []]));
        $this->postJson($url)->assertStatus(409);
        self::assertSame(1, DB::table('ai_calls')->where('organization_id', $org->id)->where('brand_id', $brand->id)->count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
