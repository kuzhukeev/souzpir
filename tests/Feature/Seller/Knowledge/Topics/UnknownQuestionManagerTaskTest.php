<?php

namespace Tests\Feature\Seller\Knowledge\Topics;

use App\Ai\Drivers\Driver;
use App\Ai\Drivers\FakeDriver;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Carbon\Carbon;
use Database\Seeders\Ai\PromptSetsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class UnknownQuestionFakeDriverE404F2 implements Driver
{
    public function __construct(private string $question, private string $reply) {}

    public function complete(#[\SensitiveParameter] array $body): array
    {
        $schema = $body['response_format']['json_schema']['name'] ?? null;
        $content = $schema === 'extraction' ? json_encode(['client_intent' => 'unclear', 'unknown_question' => $this->question], JSON_UNESCAPED_UNICODE) : $this->reply;

        return ['content' => $content, 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
            'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
    }
}

/** D-4.6: при goal->kind === unknown_fact финализаторы создают задачу менеджеру seller_tasks. */
final class UnknownQuestionManagerTaskTest extends TenancyTestCase
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
    }

    private function enableSeller($org, $brandA, $brandB): void
    {
        foreach ([$brandA, $brandB] as $brand) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_first_turn_unknown_question_creates_manager_task(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['runtime.ai_driver' => 'fake']);
        [$owner, $org, $brandA, $brandB, $membership] = $this->cabinet();
        $this->enableSeller($org, $brandA, $brandB);
        $context = new TenantContext($org->id, $brandA->id, $owner->id, $membership->id);
        $this->seedKnowledge($context);
        app()->instance(FakeDriver::class, new UnknownQuestionFakeDriverE404F2('Есть упаковка 3 кг?', 'Уточню у коллег и вернусь сегодня.'));
        [$dialog, $message] = app(TenantContextRunner::class)->run($context, function (): array {
            $dialog = Conversation::create(['state' => [], 'mode' => 'bot', 'version' => 0]);

            return [$dialog, Message::create(['conversation_id' => $dialog->id, 'role' => 'client', 'text' => 'Есть упаковка 3 кг?', 'meta' => []])];
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brandA->id]);
        $input = ['conversation_id' => $dialog->id, 'message_id' => $message->id, 'text' => $message->text, 'idempotency_key' => 'unknown-first-turn'];
        $this->postJson('/seller/turns/first', $input)->assertOk()->assertJsonPath('data.status', 'completed');

        $task = DB::table('seller_tasks')->where('organization_id', $org->id)->where('brand_id', $brandA->id)
            ->where('conversation_id', $dialog->id)->where('type', 'unknown_question')->first();
        self::assertNotNull($task);
        $payload = json_decode($task->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Есть упаковка 3 кг?', $payload['text']);
        self::assertSame('Есть упаковка 3 кг?', $payload['question']);
        self::assertSame('new', $task->status);
        self::assertNotNull($task->due_at);
        self::assertTrue(Carbon::parse($task->due_at)->isFuture());
        self::assertSame(0, DB::table('seller_tasks')->where('brand_id', $brandB->id)->count());
        Http::assertNothingSent();
    }

    public function test_followup_turn_unknown_question_creates_manager_task(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['runtime.ai_driver' => 'fake']);
        [$owner, $org, $brandA, $brandB, $membership] = $this->cabinet();
        $this->enableSeller($org, $brandA, $brandB);
        $context = new TenantContext($org->id, $brandA->id, $owner->id, $membership->id);
        $this->seedKnowledge($context);
        app()->instance(FakeDriver::class, new UnknownQuestionFakeDriverE404F2('Есть упаковка 5 кг?', 'Уточню у коллег и вернусь сегодня.'));
        [$dialog, $message] = app(TenantContextRunner::class)->run($context, function (): array {
            $dialog = Conversation::create(['state' => [], 'mode' => 'bot', 'version' => 1]);

            return [$dialog, Message::create(['conversation_id' => $dialog->id, 'role' => 'client', 'text' => 'Есть упаковка 5 кг?', 'meta' => []])];
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brandA->id]);
        $input = ['conversation_id' => $dialog->id, 'message_id' => $message->id, 'text' => $message->text,
            'idempotency_key' => 'unknown-followup-task', 'expected_version' => 1];
        $this->postJson('/seller/turns/runtime', $input)->assertOk()->assertJsonPath('data.status', 'completed');

        $task = DB::table('seller_tasks')->where('organization_id', $org->id)->where('brand_id', $brandA->id)
            ->where('conversation_id', $dialog->id)->where('type', 'unknown_question')->first();
        self::assertNotNull($task);
        $payload = json_decode($task->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Есть упаковка 5 кг?', $payload['question']);
        self::assertNotNull($task->due_at);
        self::assertSame(0, DB::table('seller_tasks')->where('brand_id', $brandB->id)->count());
        Http::assertNothingSent();
    }
}
