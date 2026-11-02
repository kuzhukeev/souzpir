<?php

namespace App\Seller\Engine\Responder;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

final class PublishedSource implements PublicationSource
{
    public function __construct(private readonly KnowledgeRepository $knowledge) {}

    public function load(TenantContext $tenant): array
    {
        if (! $tenant->organizationId || ! $tenant->brandId) {
            throw new LogicException('Published responder input unavailable.');
        }
        $snapshot = $this->knowledge->published($tenant);
        $prompt = DB::table('prompt_sets')->where('name', 'responder')->where('version', 'v1')->where('status', 'published')
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->first();
        $prompt ??= DB::table('prompt_sets')->where('name', 'responder')->where('version', 'v1')->where('status', 'published')
            ->whereNull('organization_id')->whereNull('brand_id')->first();
        $template = file_get_contents(resource_path('views/prompts/responder.blade.php'));
        if (! $prompt || ! is_string($template) || ! is_string($prompt->checksum)
            || ! hash_equals(hash('sha256', $template), $prompt->checksum)) {
            throw new LogicException('Published responder input unavailable.');
        }

        return ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
            'release_status' => 'published', 'snapshot' => $snapshot, 'prompt' => $prompt];
    }
}
