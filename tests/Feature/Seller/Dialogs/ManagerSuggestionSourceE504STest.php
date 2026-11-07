<?php

namespace Tests\Feature\Seller\Dialogs;

use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Dialogs\ManagerSuggestionExtractorInput;
use App\Seller\Dialogs\ManagerSuggestionSource;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Ai\PromptSetsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ManagerSuggestionSourceE504STest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['runtime.ai_driver' => 'fake']);
        Http::preventStrayRequests();
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->post('/test-manager-suggestion/{dialog}', function (Request $request, string $dialog) {
            try {
                return response()->json(app(ManagerSuggestionSource::class)->capture($request, $dialog, (string) $request->input('key')));
            } catch (IdempotencyConflict) {
                return response()->json(['message' => 'conflict'], 409);
            }
        });
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->post('/test-manager-extractor/{dialog}', function (Request $request, string $dialog) {
            [$input, $source] = app(ManagerSuggestionExtractorInput::class)->build($request, $dialog, (string) $request->input('key'));

            return response()->json(['contains_saved_input' => str_contains(json_encode($input->toArray(), JSON_UNESCAPED_UNICODE), 'Синтетический вопрос'), 'message_id' => $source['message_id']]);
        });
    }

    private function seeded(): array
    {
        app(PromptSetsSeeder::class)->run();
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        [$dialog, $message] = app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $owner): array {
            app(YamlKnowledgeImporter::class)->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data'));
            $dialog = Conversation::create(['state' => [], 'mode' => 'manager', 'assigned_user_id' => $owner->id]);
            $message = Message::create(['conversation_id' => $dialog->id, 'role' => 'client', 'text' => 'Синтетический вопрос', 'meta' => []]);

            return [$dialog, $message];
        });

        return [$owner, $org, $brand, $other, $tenant, $dialog, $message];
    }

    public function test_source_is_bound_to_manager_history_and_published_release(): void
    {
        [$owner, , $brand, , $tenant, $dialog, $message] = $this->seeded();
        $url = '/test-manager-suggestion/'.$dialog->id;
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $first = $this->postJson($url, ['key' => 'suggestion-1'])->assertOk()->assertJsonPath('message_id', $message->id)->json();
        $this->postJson($url, ['key' => 'suggestion-1'])->assertOk()->assertExactJson($first);
        $this->postJson('/test-manager-extractor/'.$dialog->id, ['key' => 'suggestion-1'])->assertOk()->assertJsonPath('contains_saved_input', true)->assertJsonPath('message_id', $message->id);
        self::assertArrayHasKey('release_checksum', $first);
        self::assertStringNotContainsString('Синтетический вопрос', json_encode($first, JSON_UNESCAPED_UNICODE));
        self::assertSame(1, DB::table('protected_operation_receipts')->where('operation', 'seller.manager_suggestion.source')->count());
        app(TenantContextRunner::class)->run($tenant, fn () => Message::create(['conversation_id' => $dialog->id, 'role' => 'client', 'text' => 'Новый вопрос', 'meta' => []]));
        $this->postJson($url, ['key' => 'suggestion-1'])->assertStatus(409);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_source_rejects_closed_or_outdated_conversation(): void
    {
        [$owner, , $brand, , $tenant, $dialog] = $this->seeded();
        $url = '/test-manager-suggestion/'.$dialog->id;
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        DB::table('conversations')->where('id', $dialog->id)->update(['stage' => 'done']);
        $this->postJson($url, ['key' => 'closed'])->assertStatus(409);
        DB::table('conversations')->where('id', $dialog->id)->update(['stage' => 'open', 'mode' => 'bot']);
        $this->postJson($url, ['key' => 'wrong-mode'])->assertNotFound();
        DB::table('conversations')->where('id', $dialog->id)->update(['mode' => 'manager']);
        app(TenantContextRunner::class)->run($tenant, fn () => Message::create(['conversation_id' => $dialog->id, 'role' => 'manager', 'text' => 'Ответ менеджера', 'meta' => []]));
        $this->postJson($url, ['key' => 'answered'])->assertStatus(409);
        self::assertSame(0, DB::table('protected_operation_receipts')->where('operation', 'seller.manager_suggestion.source')->count());
        Http::assertNothingSent();
    }

    public function test_source_rejects_wrong_actor_brand_and_driver(): void
    {
        [$owner, $org, $brand, $other, , $dialog] = $this->seeded();
        $url = '/test-manager-suggestion/'.$dialog->id;
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson($url, ['key' => ''])->assertUnprocessable();
        config(['runtime.ai_driver' => 'openrouter']);
        $this->postJson($url, ['key' => 'x'])->assertStatus(503);
        config(['runtime.ai_driver' => 'fake']);
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        DB::table('conversations')->where('id', $dialog->id)->update(['assigned_user_id' => $manager->id]);
        $this->postJson($url, ['key' => 'x'])->assertNotFound();
        DB::table('conversations')->where('id', $dialog->id)->update(['assigned_user_id' => $owner->id]);
        $this->withSession(['brand_id' => $other->id])->postJson($url, ['key' => 'x'])->assertStatus(409);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->postJson($url, ['key' => 'x'])->assertForbidden();
        self::assertSame(0, DB::table('protected_operation_receipts')->where('operation', 'seller.manager_suggestion.source')->count());
        Http::assertNothingSent();
    }
}
