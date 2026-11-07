<?php

namespace Tests\Feature\SellerRuntime\Turns;

use App\Ai\Drivers\Driver;
use App\Ai\Drivers\FakeDriver;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Ai\PromptSetsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FollowupProtectedFake implements Driver
{
    public int $calls = 0;

    public function complete(array $body): array
    {
        $this->calls++;

        return ['content' => isset($body['response_format']) ? '{"client_intent":"answer_only"}' : 'Спасибо за сообщение!',
            'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
            'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
    }
}

final class FollowupHttpTest extends TenancyTestCase
{
    public function refreshDatabase()
    {
        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = false;
    }

    private function seedKnowledge(TenantContext $context): void
    {
        app(PromptSetsSeeder::class)->run();
        app(TenantContextRunner::class)->run($context, fn () => app(YamlKnowledgeImporter::class)
            ->importAndPublish($context, base_path('tests/Feature/Seller/Knowledge/Fixtures/data')));
        app()->instance(FakeDriver::class, new FollowupProtectedFake);
    }

    public function test_fake_followup_and_suggestion_are_saved_and_replayed_without_delivery(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['runtime.ai_driver' => 'fake']);
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
            'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $context = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        $this->seedKnowledge($context);
        $fake = app(FakeDriver::class);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        foreach ([['bot', 1, 'completed'], ['suggest', 0, 'suggested']] as [$mode, $version, $status]) {
            [$dialog, $message] = app(TenantContextRunner::class)->run($context, function () use ($mode, $version): array {
                $dialog = Conversation::create(['state' => [], 'mode' => $mode, 'version' => $version]);
                $message = Message::create(['conversation_id' => $dialog->id, 'role' => 'client', 'text' => 'Контакт +7 999 111-22-33', 'meta' => []]);

                return [$dialog, $message];
            });
            $input = ['conversation_id' => $dialog->id, 'message_id' => $message->id, 'text' => $message->text,
                'idempotency_key' => 'followup-'.$mode, 'expected_version' => $version];
            $this->postJson('/seller/turns/runtime', $input)->assertOk()->assertJsonPath('data.status', $status);
            $this->postJson('/seller/turns/runtime', $input)->assertOk()->assertJsonPath('data.status', $status);
            $this->assertSame($mode === 'bot' ? 2 : 4, $fake->calls);
            $this->assertSame($version + 1, (int) DB::table('conversations')->where('id', $dialog->id)->value('version'));
            $this->assertSame(1, DB::table('seller_turns')->where('conversation_id', $dialog->id)->count());
            $this->assertSame($mode === 'bot' ? 1 : 0, DB::table('messages')->where('conversation_id', $dialog->id)->where('role', 'agent')->count());
            $this->assertNull(DB::table('messages')->where('conversation_id', $dialog->id)->where('role', 'agent')->value('delivered_at'));
            if ($mode === 'suggest') {
                $outcome = json_decode(DB::table('seller_turns')->where('conversation_id', $dialog->id)->value('outcome'), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame('suggested', $outcome['status']);
                $this->assertNotEmpty($outcome['messages'][0]['text']);
            }
        }
        Http::assertNothingSent();
    }

    public function test_followup_rejects_wrong_revision_other_brand_and_viewer(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['runtime.ai_driver' => 'fake']);
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
                'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        [$dialog, $message] = app(TenantContextRunner::class)->run(new TenantContext($org->id, $brand->id, $owner->id, $membership->id), function (): array {
            $dialog = Conversation::create(['state' => [], 'mode' => 'bot', 'version' => 1]);

            return [$dialog, Message::create(['conversation_id' => $dialog->id, 'role' => 'client', 'text' => 'Вопрос', 'meta' => []])];
        });
        $this->seedKnowledge(new TenantContext($org->id, $brand->id, $owner->id, $membership->id));
        $input = ['conversation_id' => $dialog->id, 'message_id' => $message->id, 'text' => $message->text,
            'idempotency_key' => 'followup-rights', 'expected_version' => 1];
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/seller/turns/runtime', [...$input, 'expected_version' => 0])->assertConflict();
        $this->postJson('/seller/turns/runtime', [...$input, 'expected_version' => -1])->assertUnprocessable();
        $this->postJson('/seller/turns/runtime', $input)->assertOk();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->postJson('/seller/turns/runtime', $input)->assertStatus(503);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/seller/turns/runtime', $input)->assertForbidden();
        Http::assertNothingSent();
    }
}
