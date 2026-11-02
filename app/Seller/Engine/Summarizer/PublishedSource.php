<?php

namespace App\Seller\Engine\Summarizer;

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
        $prompt = DB::table('prompt_sets')->where('name', 'summary')->where('version', 'v1')->where('status', 'published')
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->first();
        $prompt ??= DB::table('prompt_sets')->where('name', 'summary')->where('version', 'v1')->where('status', 'published')
            ->whereNull('organization_id')->whereNull('brand_id')->first();
        $template = file_get_contents(resource_path('views/prompts/summary.blade.php'));
        if (! $prompt || ! is_string($template) || ! is_string($prompt->checksum)
            || ! hash_equals(hash('sha256', $template), $prompt->checksum)) {
            throw new LogicException('Published summary input unavailable.');
        }
        $history = DB::table('messages')->where('conversation_id', $conversationId)
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->whereIn('role', ['client', 'agent'])->orderBy('history_sequence')
            ->get(['role', 'text'])->map(fn ($message) => ['role' => $message->role, 'text' => $message->text])->all();

        return ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
            'conversation_id' => $conversationId, 'release_status' => 'published', 'snapshot' => $snapshot,
            'prompt' => $prompt, 'history' => $history, 'previous' => $conversation->summary];
    }
}
