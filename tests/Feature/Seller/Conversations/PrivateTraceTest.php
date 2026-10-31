<?php

namespace Tests\Feature\Seller\Conversations;

use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Channels\Core\InboundRecorder;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Data\TurnOutcome;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Tenancy\TenancyTestCase;
use Throwable;

final class PrivateTraceTest extends TenancyTestCase
{
    private string|false $originalIgnoreArgs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalIgnoreArgs = ini_set('zend.exception_ignore_args', '0');
        $this->assertNotFalse($this->originalIgnoreArgs);
    }

    protected function tearDown(): void
    {
        if ($this->originalIgnoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->originalIgnoreArgs);
        }
        parent::tearDown();
    }

    private function assertPrivate(Throwable $exception, array $secrets): void
    {
        $this->assertNull($exception->getPrevious());
        $rendered = $exception->getMessage().$exception->getTraceAsString();
        $sellerFrames = 0;
        foreach ($exception->getTrace() as $frame) {
            if (str_starts_with($frame['class'] ?? '', 'App\\Seller\\')) {
                $sellerFrames++;
                $rendered .= json_encode($frame['args'] ?? [], JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
        }
        $this->assertGreaterThan(0, $sellerFrames);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $rendered);
        }
    }

    public function test_inbound_and_claim_errors_do_not_expose_payload_or_client_text(): void
    {
        $this->assertSame('0', ini_get('zend.exception_ignore_args'));
        [, $org, $brand] = $this->cabinet();
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        [$channel, $conversation, $input] = $this->within($org, $brand, function (): array {
            $channel = ChannelAccount::create(['type' => 'api']);
            $conversation = Conversation::create(['channel_account_id' => $channel->id, 'mode' => 'bot', 'state' => []]);
            $input = app(InboundRecorder::class)->ingest(app(TenantContext::class), $channel->id, 'event-1', [], $conversation->id, 'original');

            return [$channel, $conversation, $input];
        });

        try {
            app(InboundRecorder::class)->ingest($tenant, $channel->id, 'event-1', [], $conversation->id, 'PRIVATE_INBOUND_TEXT');
            $this->fail('Expected an inbound conflict.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertPrivate($exception, ['PRIVATE_INBOUND_TEXT']);
        }

        try {
            app(InboundRecorder::class)->record($tenant, $channel->id, 'event-1', ['private' => "PRIVATE_PAYLOAD\xff"]);
            $this->fail('Expected invalid UTF-8.');
        } catch (\RuntimeException $exception) {
            $this->assertPrivate($exception, ['PRIVATE_PAYLOAD']);
        }

        try {
            app(InboundRecorder::class)->record($tenant, $channel->id, 'event-db', ['private' => "PRIVATE_DB_BOUND\0"]);
            $this->fail('Expected PostgreSQL to reject NUL in JSON.');
        } catch (\RuntimeException $exception) {
            $this->assertPrivate($exception, ['PRIVATE_DB_BOUND']);
        }

        try {
            app(ConversationRepository::class)->claimTurn($tenant, new TurnCommand($conversation->id, $input->id, 'PRIVATE_CLIENT_TEXT', $input->external_event_id, 'PRIVATE_KEY', 0));
            $this->fail('Expected a claim conflict.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertPrivate($exception, ['PRIVATE_CLIENT_TEXT', 'PRIVATE_KEY']);
        }
    }

    public function test_commit_error_discards_nested_serialization_trace_and_claim_token(): void
    {
        $this->assertSame('0', ini_get('zend.exception_ignore_args'));
        [, $org, $brand] = $this->cabinet();
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        [$conversation, $input] = $this->within($org, $brand, function (): array {
            $conversation = Conversation::create(['mode' => 'bot', 'state' => []]);
            $input = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'original', 'meta' => []]);

            return [$conversation, $input];
        });
        $repository = app(ConversationRepository::class);
        $claim = $repository->claimTurn($tenant, new TurnCommand($conversation->id, $input->id, 'original', null, (string) Str::ulid(), 0));
        $outcome = new TurnOutcome($claim->turnId, $conversation->id, 'completed', 1, null, null, null, [], ['private' => "PRIVATE_OUTCOME\xff"]);

        try {
            $repository->commitTurn($claim, $outcome);
            $this->fail('Expected invalid UTF-8.');
        } catch (\RuntimeException $exception) {
            $this->assertPrivate($exception, ['PRIVATE_OUTCOME', $claim->token()]);
        }

        $this->assertDatabaseHas('seller_turns', ['id' => $claim->turnId, 'status' => 'processing']);
    }
}
