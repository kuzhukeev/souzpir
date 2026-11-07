<?php

namespace Tests\Feature\Seller\Runtime;

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

final class UnknownQuestionFakeDriverE404F implements Driver
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

/** D-4.6: log_unknown_question пока подключён только через общий ToolRunner и счётчик Policy::applyExtraction
 *  (последующие ходы). Создание задачи менеджеру в момент финализации хода — в «Нужно от интегратора».
 */
final class UnknownQuestionTaskE404FTest extends TenancyTestCase
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

    public function test_followup_turn_unknown_question_records_it_on_the_conversation_and_in_analytics(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['runtime.ai_driver' => 'fake']);
        [$owner, $org, $brandA, $brandB, $membership] = $this->cabinet();
        foreach ([$brandA, $brandB] as $brand) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $context = new TenantContext($org->id, $brandA->id, $owner->id, $membership->id);
        $this->seedKnowledge($context);
        app()->instance(FakeDriver::class, new UnknownQuestionFakeDriverE404F('Есть упаковка 5 кг?', 'Уточню у коллег и вернусь сегодня.'));
        [$dialog, $message] = app(TenantContextRunner::class)->run($context, function (): array {
            $dialog = Conversation::create(['state' => [], 'mode' => 'bot', 'version' => 1]);

            return [$dialog, Message::create(['conversation_id' => $dialog->id, 'role' => 'client', 'text' => 'Есть упаковка 5 кг?', 'meta' => []])];
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brandA->id]);
        $input = ['conversation_id' => $dialog->id, 'message_id' => $message->id, 'text' => $message->text,
            'idempotency_key' => 'unknown-followup-turn', 'expected_version' => 1];
        $this->postJson('/seller/turns/runtime', $input)->assertOk()->assertJsonPath('data.status', 'completed');

        $state = json_decode(DB::table('conversations')->where('id', $dialog->id)->value('state'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['Есть упаковка 5 кг?'], $state['unknown_questions']);
        self::assertSame(1, $state['counters']['unknown_facts']);
        $agentMeta = json_decode(DB::table('messages')->where('conversation_id', $dialog->id)->where('role', 'agent')->value('meta'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('unknown_fact', $agentMeta['goal_kind']);
        self::assertSame(0, DB::table('conversations')->where('brand_id', $brandB->id)->whereJsonLength('state->unknown_questions', '>', 0)->count());
        Http::assertNothingSent();
    }
}
