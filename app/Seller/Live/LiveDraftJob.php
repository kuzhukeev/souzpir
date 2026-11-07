<?php

namespace App\Seller\Live;

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Seller\Conversations\ActiveTenantLock;
use App\Seller\Conversations\CanonicalJson;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Conversations\Models\SellerTurn;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeNotReady;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class LiveDraftJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $organizationId, public string $brandId, public string $conversationId, public string $messageId) {}

    public function handle(): void
    {
        if (! config('features.LV-01.defaults.enabled', false)) {
            return;
        }
        $tenant = new TenantContext($this->organizationId, $this->brandId, source: 'job');
        app(TenantContextRunner::class)->run($tenant, fn () => $this->draft($tenant));
    }

    private function draft(TenantContext $tenant): void
    {
        $conversation = Conversation::find($this->conversationId);
        $message = Message::where('conversation_id', $this->conversationId)->whereKey($this->messageId)->where('role', 'client')->first();
        if (! $conversation || ! $message || $conversation->mode !== 'suggest' || trim((string) $message->text) === '') {
            return;
        }
        $config = (array) config('features.LV-01.defaults');
        try {
            $knowledge = app(KnowledgeRepository::class)->published($tenant);
        } catch (KnowledgeNotReady) {
            Log::warning('live_draft.skipped', ['reason' => 'knowledge_not_ready', 'conversation_id' => $this->conversationId]);

            return;
        }
        $history = Message::where('conversation_id', $this->conversationId)->whereIn('role', ['client', 'agent', 'manager'])
            ->orderByDesc('id')->limit((int) $config['history_messages'])->get(['role', 'text'])->reverse()->values()
            ->map(fn ($m) => ['role' => $m->role === 'client' ? 'user' : 'assistant', 'content' => (string) $m->text])->all();
        $messages = [['role' => 'system', 'content' => DraftPrompt::system($knowledge, (int) $config['max_knowledge_bytes'])], ...$history];

        try {
            $claim = app(ConversationRepository::class)->claimTurn($tenant, new TurnCommand(
                $this->conversationId, $this->messageId, (string) $message->text, $message->external_event_id,
                'live-draft-'.$this->messageId, (int) $conversation->version));
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 409) {
                return;
            }
            throw $e;
        }
        if ($claim->status !== 'claimed') {
            return;
        }

        $text = null;
        try {
            $result = app(AiGateway::class)->complete(
                new AiRequest($messages, (string) $config['model'], (float) $config['temperature'], maxTokens: (int) $config['max_tokens'], promptVersion: DraftPrompt::VERSION, promptChecksum: DraftPrompt::checksum()),
                new AiContext($this->organizationId, $this->brandId, 'seller.live_draft', 'seller', 'conversation', $this->conversationId,
                    'live-draft-ai-'.$this->messageId, ['conversation_content', 'knowledge_internal']));
            $text = $result->ok() ? DraftPrompt::clean((string) $result->content) : null;
        } catch (Throwable $e) {
            Log::warning('live_draft.gateway_failed', ['conversation_id' => $this->conversationId, 'class' => $e::class]);
        }

        $this->finish($tenant, $claim->turnId, $claim->baseVersion, $text);
    }

    private function finish(TenantContext $tenant, string $turnId, int $baseVersion, ?string $text): void
    {
        $status = $text === null || $text === '' ? 'failed' : 'suggested';
        DB::transaction(function () use ($tenant, $turnId, $baseVersion, $status, $text): void {
            ActiveTenantLock::acquire($tenant);
            $conversation = Conversation::whereKey($this->conversationId)->lockForUpdate()->firstOrFail();
            $turn = SellerTurn::whereKey($turnId)->lockForUpdate()->firstOrFail();
            if ($turn->status !== 'processing' || $conversation->version !== $baseVersion || $turn->base_version !== $baseVersion
                || ($status === 'suggested' && $conversation->mode !== 'suggest')) {
                Log::info('live_draft.discarded', ['conversation_id' => $this->conversationId, 'turn_status' => $turn->status]);

                return;
            }
            $outcome = ['status' => $status, 'turnId' => $turnId, 'conversationId' => $this->conversationId, 'version' => $baseVersion + 1,
                'messages' => $status === 'suggested' ? [['text' => $text]] : []];
            DB::table('conversations')->where('id', $this->conversationId)->update(['version' => $baseVersion + 1, 'updated_at' => now()]);
            DB::table('seller_turns')->where('id', $turnId)->where('status', 'processing')->update([
                'status' => $status, 'outcome' => json_encode($outcome, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
                'outcome_hash' => CanonicalJson::hash($outcome), 'updated_at' => now()]);
        });
    }
}
