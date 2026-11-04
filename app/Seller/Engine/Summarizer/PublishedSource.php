<?php

namespace App\Seller\Engine\Summarizer;

use App\Seller\Engine\BrandPromptSets;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

final class PublishedSource implements PublicationSource
{
    public function __construct(private readonly KnowledgeRepository $knowledge) {}

    public function load(TenantContext $tenant, string $conversationId): array
    {
        if (! $tenant->organizationId || ! $tenant->brandId || $conversationId === '') {
            throw new LogicException('Published summary input unavailable.');
        }
        $conversation = DB::table('conversations')->where('id', $conversationId)
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->first(['summary']);
        if (! $conversation) {
            throw new LogicException('Published summary input unavailable.');
        }
        $snapshot = $this->knowledge->published($tenant);
        $prompt = app(BrandPromptSets::class)->load($tenant, $snapshot, 'summary');
        $history = DB::table('messages')->where('conversation_id', $conversationId)
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->whereIn('role', ['client', 'agent'])->orderBy('history_sequence')
            ->get(['role', 'text'])->map(fn ($message) => ['role' => $message->role, 'text' => $message->text])->all();

        return ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
            'conversation_id' => $conversationId, 'release_status' => 'published', 'snapshot' => $snapshot,
            'prompt' => $prompt, 'history' => $history, 'previous' => $conversation->summary];
    }
}
