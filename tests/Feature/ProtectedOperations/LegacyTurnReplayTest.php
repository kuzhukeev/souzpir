<?php

namespace Tests\Feature\ProtectedOperations;

use App\Seller\Conversations\CanonicalJson;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Data\TurnOutcome;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class LegacyTurnReplayTest extends TenancyTestCase
{
    public function test_historical_replay_does_not_write_or_disclose_the_result(): void
    {
        Http::fake();
        Queue::fake();
        [, $org, $brand, $otherBrand] = $this->cabinet();
        [$conversation, $input] = $this->within($org, $brand, function (): array {
            $conversation = Conversation::create(['mode' => 'bot', 'state' => []]);
            $input = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Input', 'meta' => []]);

            return [$conversation, $input];
        });
        $repo = app(ConversationRepository::class);
        $claim = $repo->claimTurn(new TenantContext($org->id, $brand->id, source: 'job'), new TurnCommand($conversation->id, $input->id, $input->text, null, 'historical', 0));
        $id = (string) Str::ulid();
        $outcome = new TurnOutcome($claim->turnId, $conversation->id, 'completed', 1, $id, ['private' => 'PRIVATE_RESULT_GOAL'], null, [], ['private' => 'PRIVATE_RESULT_STATE'], [['id' => $id, 'role' => 'agent', 'text' => 'PRIVATE_RESULT_MESSAGE']]);
        // Direct DB setup represents a historical result, not authority for a new one.
        $this->within($org, $brand, function () use ($conversation, $claim, $id, $outcome): void {
            $message = new Message(['conversation_id' => $conversation->id, 'role' => 'agent', 'text' => 'PRIVATE_RESULT_MESSAGE', 'meta' => []]);
            $message->id = $id;
            $message->save();
            DB::table('conversations')->where('id', $conversation->id)->update(['state' => json_encode($outcome->state, JSON_THROW_ON_ERROR), 'version' => 1]);
            DB::table('seller_turns')->where('id', $claim->turnId)->update(['status' => 'completed', 'outcome' => json_encode($outcome->toArray(), JSON_THROW_ON_ERROR), 'outcome_hash' => CanonicalJson::hash($outcome->toArray())]);
        });
        $snapshot = fn (): string => json_encode([
            DB::table('conversations')->where('id', $conversation->id)->first(),
            DB::table('seller_turns')->where('id', $claim->turnId)->first(),
            DB::table('messages')->where('conversation_id', $conversation->id)->orderBy('id')->get(),
            DB::table('seller_tasks')->count(),
            DB::table('outbox_events')->count(),
        ], JSON_THROW_ON_ERROR);
        $before = $snapshot();

        self::assertNull($repo->commitTurn($claim, $outcome));
        self::assertNull($repo->commitTurn($claim, $outcome));
        self::assertSame($before, $snapshot());
        $forged = new TurnClaim('claimed', $claim->turnId, $claim->conversationId, $claim->organizationId, $claim->brandId, $claim->baseVersion, str_repeat('a', 64));
        $foreign = new TurnClaim('claimed', $claim->turnId, $claim->conversationId, $claim->organizationId, $otherBrand->id, $claim->baseVersion, $claim->token());
        $changed = new TurnOutcome($claim->turnId, $conversation->id, 'completed', 1, $id, ['private' => 'PRIVATE_RESULT_GOAL'], null, [], ['private' => 'different'], $outcome->messages);
        foreach ([[$forged, $outcome, HttpException::class], [$foreign, $outcome, ModelNotFoundException::class], [$claim, $changed, HttpException::class]] as [$candidate, $candidateOutcome, $expected]) {
            try {
                $repo->commitTurn($candidate, $candidateOutcome);
                self::fail('Invalid historical replay was accepted.');
            } catch (HttpException|ModelNotFoundException $error) {
                self::assertInstanceOf($expected, $error);
                if ($error instanceof HttpException) {
                    self::assertSame(409, $error->getStatusCode());
                }
                self::assertNull($error->getPrevious());
                $details = $error->getMessage().$error->getTraceAsString().json_encode($error->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_THROW_ON_ERROR);
                self::assertStringNotContainsString('PRIVATE_RESULT_', $details);
                self::assertStringNotContainsString($claim->token(), $details);
            }
            self::assertSame($before, $snapshot());
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
