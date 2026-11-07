<?php

namespace Tests\Feature\Seller\Dialogs;

use App\Ai\CanonicalJson;
use App\Ai\ContactMasker;
use App\Ai\Gateway;
use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Dialogs\ManagerSuggestionExtractorInput;
use App\Seller\Dialogs\ManagerSuggestionResponderInput;
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

final class ManagerSuggestionControllerE504STest extends TestCase
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

    private function signIn(User $user, Brand $brand): void
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

    private function writeFake(string $directory, object $request, string $content): void
    {
        $path = $directory.'/'.CanonicalJson::hash(app(ContactMasker::class)->request($request->toArray())).'.json';
        file_put_contents($path, json_encode(['result' => ['content' => $content, 'toolCalls' => [],
            'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $request->model, 'latencyMs' => 1, 'costMicros' => 0]], JSON_THROW_ON_ERROR));
    }

    public function test_generates_a_draft_reply_without_sending_and_replays_idempotently(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        config(['runtime.ai_driver' => 'fake']);
        app(PromptSetsSeeder::class)->run();
        $owner = User::create(['name' => 'Test', 'email' => Str::random(12).'@example.test', 'password' => 'synthetic-test-password']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $owner->refresh();
        $org = Organization::create(['name' => 'Test', 'owner_user_id' => $owner->id]);
        [$member, $brand, $other] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), fn () => [
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']), Brand::create(['name' => 'Test']), Brand::create(['name' => 'Other']),
        ]);
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $dialog = app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $owner) {
            app(YamlKnowledgeImporter::class)->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data'));
            $dialog = Conversation::create(['state' => [], 'mode' => 'manager', 'assigned_user_id' => $owner->id]);
            Message::create(['conversation_id' => $dialog->id, 'role' => 'client', 'text' => 'Синтетический вопрос', 'meta' => []]);

            return $dialog;
        });
        $directory = base_path('.local/runtime/e504s-controller');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        config(['features.E0-05.defaults.fixture_directory' => $directory]);
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->post('/test-manager-prepare/{dialog}', function (Request $request, string $dialog) use ($directory) {
            $key = (string) $request->input('key');
            [$extractor] = app(ManagerSuggestionExtractorInput::class)->build($request, $dialog, $key);
            $this->writeFake($directory, $extractor, '{"client_intent":"answer_only","slot_updates":{}}');
            app(Gateway::class)->completeManagerSuggestionExtractor($request, $dialog, $key);
            [$responder] = DB::transaction(fn () => app(ManagerSuggestionResponderInput::class)->build($request, $dialog, $key));
            $this->writeFake($directory, $responder, 'Спасибо за сообщение. Уточните, пожалуйста, детали запроса?');

            return response()->json(['ok' => true]);
        });
        $this->signIn($owner, $brand);
        $this->postJson('/test-manager-prepare/'.$dialog->id, ['key' => 'suggestion-1'])->assertOk();
        $url = '/seller/dialogs/'.$dialog->id.'/suggestion';
        $first = $this->postJson($url, ['key' => 'suggestion-1'])->assertOk()->assertJsonPath('status', 'ready')->json();
        self::assertSame('Спасибо за сообщение. Уточните, пожалуйста, детали запроса?', $first['suggestion']);
        $this->postJson($url, ['key' => 'suggestion-1'])->assertOk()->assertExactJson($first);
        self::assertSame(2, DB::table('ai_calls')->where('organization_id', $org->id)->where('brand_id', $brand->id)->count());
        self::assertSame(2, DB::table('audit_logs')->where('action', 'seller.dialog.suggestion.generated')->count());
        app(TenantContextRunner::class)->run($tenant, fn () => Message::create(['conversation_id' => $dialog->id, 'role' => 'client', 'text' => 'Новый вопрос', 'meta' => []]));
        $this->postJson($url, ['key' => 'suggestion-1'])->assertStatus(422);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        $this->withSession(['brand_id' => $other->id])->postJson($url, ['key' => 'suggestion-1'])->assertStatus(422);
    }

    /** Authorization failures are already sanitized by Gateway::completeManagerSuggestionExtractor into a generic unavailable status. */
    public function test_rejects_a_manager_not_assigned_to_the_dialog(): void
    {
        config(['runtime.ai_driver' => 'fake']);
        app(PromptSetsSeeder::class)->run();
        $owner = User::create(['name' => 'Test', 'email' => Str::random(12).'@example.test', 'password' => 'synthetic-test-password']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $owner->refresh();
        $manager = User::create(['name' => 'Manager', 'email' => Str::random(12).'@example.test', 'password' => 'synthetic-test-password']);
        $manager->forceFill(['email_verified_at' => now()])->save();
        $manager->refresh();
        $org = Organization::create(['name' => 'Test', 'owner_user_id' => $owner->id]);
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        [$member, $brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), function () use ($owner, $manager) {
            $member = Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
            $brand = Brand::create(['name' => 'Test']);
            Membership::create(['user_id' => $manager->id, 'role' => 'member', 'brand_ids' => [$brand->id], 'settings' => ['sales_manager' => true]]);

            return [$member, $brand];
        });
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $dialog = app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $owner) {
            app(YamlKnowledgeImporter::class)->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data'));
            $dialog = Conversation::create(['state' => [], 'mode' => 'manager', 'assigned_user_id' => $owner->id]);
            Message::create(['conversation_id' => $dialog->id, 'role' => 'client', 'text' => 'Синтетический вопрос', 'meta' => []]);

            return $dialog;
        });
        $this->signIn($manager, $brand);
        $this->postJson('/seller/dialogs/'.$dialog->id.'/suggestion', ['key' => 'x'])->assertStatus(422)->assertJsonPath('status', 'unavailable');
        self::assertSame(0, DB::table('ai_calls')->count());
    }
}
