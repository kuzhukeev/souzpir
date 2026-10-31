<?php

namespace Tests\Feature\ProtectedOperations;

use App\Ai\CanonicalJson;
use App\Ai\ContactMasker;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Diagnostic of existing behavior, not an accepted security guarantee. */
final class GatewayReplayBindingDiagnosticTest extends TestCase
{
    public function test_diagnostic_gateway_can_replay_a_different_calls_encrypted_result(): void
    {
        // This proves the pre-fix vulnerability; never count it as a passing security test.
        if (getenv('C02_DIAGNOSTIC_EXPECT_UNSAFE') !== '1') {
            self::markTestSkipped('Opt-in diagnostic for the pre-fix Gateway only.');
        }
        Http::preventStrayRequests();
        config(['runtime.ai_driver' => 'fake']);
        $org = (string) Str::ulid();
        $brand = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $org, 'name' => 'Synthetic', 'status' => 'active', 'settings' => '{}']);
        DB::table('brands')->insert(['id' => $brand, 'organization_id' => $org, 'name' => 'Synthetic brand', 'status' => 'active', 'theme' => 'neutral', 'settings' => '{}']);
        $tenant = new TenantContext($org, $brand, source: 'job');
        $fixtureDir = storage_path('app/c02-binding-fixtures-'.Str::random(12));
        mkdir($fixtureDir, 0700, true);
        config(['features.E0-05.defaults.fixture_directory' => $fixtureDir]);

        $calls = [];
        foreach (['first', 'second'] as $label) {
            [$conversation, $input] = app(TenantContextRunner::class)->run($tenant, function () use ($label): array {
                $conversation = Conversation::create(['state' => []]);
                $input = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Synthetic '.$label, 'meta' => []]);

                return [$conversation, $input];
            });
            $claim = app(ConversationRepository::class)->claimTurn($tenant, new TurnCommand($conversation->id, $input->id, $input->text, null, 'diagnostic-'.$label, 0));
            $turn = DB::table('seller_turns')->where('id', $claim->turnId)->first();
            self::assertSame(1, (int) $turn->base_history_revision);
            self::assertSame(1, (int) $turn->cutoff_sequence);
            $request = new AiRequest([['role' => 'user', 'content' => 'Synthetic '.$label]], 'google/gemini-2.5-flash', 0.0);
            $context = new AiContext($org, $brand, 'responder', 'seller', 'conversation', $conversation->id, 'diagnostic-key-'.$label, ['conversation_content']);
            $body = app(ContactMasker::class)->request($request->toArray());
            $result = ['content' => 'Synthetic output '.$label, 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $request->model, 'latencyMs' => 1, 'costMicros' => 0];
            file_put_contents($fixtureDir.'/'.CanonicalJson::hash($body).'.json', json_encode(['result' => $result], JSON_THROW_ON_ERROR));
            $actual = app(TenantContextRunner::class)->run($tenant, fn () => app(AiGateway::class)->complete($request, $context));
            $calls[] = compact('request', 'context', 'actual');
        }

        self::assertNotSame($calls[0]['actual']->aiCallId, $calls[1]['actual']->aiCallId);
        $correct = app(TenantContextRunner::class)->run($tenant, fn () => app(AiGateway::class)->complete($calls[0]['request'], $calls[0]['context']));
        self::assertSame($calls[0]['actual']->aiCallId, $correct->aiCallId);
        self::assertSame('Synthetic output first', $correct->content);
        self::assertSame(2, DB::table('ai_calls')->where('organization_id', $org)->count());

        // Deliberate DB tampering is only a test attack; it is not a proposed adapter.
        $otherCiphertext = DB::table('ai_calls')->where('id', $calls[1]['actual']->aiCallId)->value('result_encrypted');
        self::assertIsString($otherCiphertext);
        DB::table('ai_calls')->where('id', $calls[0]['actual']->aiCallId)->update(['result_encrypted' => $otherCiphertext]);
        $wrong = app(TenantContextRunner::class)->run($tenant, fn () => app(AiGateway::class)->complete($calls[0]['request'], $calls[0]['context']));
        // Current unsafe behavior: the first context receives the second result and ID.
        self::assertSame($calls[1]['actual']->aiCallId, $wrong->aiCallId);
        self::assertSame('Synthetic output second', $wrong->content);
        self::assertSame(2, DB::table('ai_calls')->where('organization_id', $org)->count());

        $otherOrg = (string) Str::ulid();
        $otherBrand = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $otherOrg, 'name' => 'Other synthetic', 'status' => 'active', 'settings' => '{}']);
        DB::table('brands')->insert(['id' => $otherBrand, 'organization_id' => $otherOrg, 'name' => 'Other brand', 'status' => 'active', 'theme' => 'neutral', 'settings' => '{}']);
        $otherTenant = new TenantContext($otherOrg, $otherBrand, source: 'job');
        [$otherConversation, $otherInput] = app(TenantContextRunner::class)->run($otherTenant, function (): array {
            $conversation = Conversation::create(['state' => []]);
            $input = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Synthetic other tenant', 'meta' => []]);

            return [$conversation, $input];
        });
        $otherClaim = app(ConversationRepository::class)->claimTurn($otherTenant, new TurnCommand($otherConversation->id, $otherInput->id, $otherInput->text, null, 'diagnostic-other-tenant', 0));
        self::assertSame(1, (int) DB::table('seller_turns')->where('id', $otherClaim->turnId)->value('base_history_revision'));
        $otherRequest = new AiRequest([['role' => 'user', 'content' => 'Synthetic other tenant']], 'google/gemini-2.5-flash', 0.0);
        $otherContext = new AiContext($otherOrg, $otherBrand, 'responder', 'seller', 'conversation', $otherConversation->id, 'diagnostic-other-key', ['conversation_content']);
        $otherBody = app(ContactMasker::class)->request($otherRequest->toArray());
        $otherData = ['content' => 'Synthetic foreign output', 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $otherRequest->model, 'latencyMs' => 1, 'costMicros' => 0];
        file_put_contents($fixtureDir.'/'.CanonicalJson::hash($otherBody).'.json', json_encode(['result' => $otherData], JSON_THROW_ON_ERROR));
        $otherActual = app(TenantContextRunner::class)->run($otherTenant, fn () => app(AiGateway::class)->complete($otherRequest, $otherContext));
        $foreignCiphertext = DB::table('ai_calls')->where('id', $otherActual->aiCallId)->value('result_encrypted');
        DB::table('ai_calls')->where('id', $calls[0]['actual']->aiCallId)->update(['result_encrypted' => $foreignCiphertext]);
        $foreign = app(TenantContextRunner::class)->run($tenant, fn () => app(AiGateway::class)->complete($calls[0]['request'], $calls[0]['context']));
        self::assertSame($otherActual->aiCallId, $foreign->aiCallId);
        self::assertSame('Synthetic foreign output', $foreign->content);
        self::assertSame(3, DB::table('ai_calls')->whereIn('organization_id', [$org, $otherOrg])->count());
        Http::assertNothingSent();
    }
}
