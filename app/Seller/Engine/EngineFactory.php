<?php

namespace App\Seller\Engine;

use App\Contracts\Ai\AiGateway;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use LogicException;

/** Runtime composition boundary; Policy and Extractor share a repository-loaded release. */
final class EngineFactory
{
    public function forRelease(TenantContext $tenant, string $releaseId, AiGateway $gateway, string $model, float $temperature, string $agentName, string $promptVersion = 'v1'): EngineComponents
    {
        $current = app(TenantContext::class);
        if ($tenant->organizationId !== $current->organizationId || $tenant->brandId === null || $tenant->brandId !== $current->brandId) {
            throw new LogicException('Active tenant does not match engine tenant.');
        }
        $snapshot = app(KnowledgeRepository::class)->forRelease($tenant, $releaseId, false);
        $prompt = app(ExtractorPromptLoader::class)->load($tenant, $promptVersion);

        return new EngineComponents(new Policy($snapshot), new Extractor($gateway, $snapshot, $prompt, $model, $temperature, $agentName));
    }
}
