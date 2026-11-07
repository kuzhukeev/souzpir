<?php

namespace App\Seller\Knowledge\Contracts;

use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;

interface KnowledgeRepository
{
    /** Trusted runtime access; HTTP callers must separately authorize seller.knowledge.read. */
    public function published(TenantContext $context): KnowledgeSnapshot;

    public function forRelease(TenantContext $context, string $id, bool $allowDraft = false): KnowledgeSnapshot;

    public function createDraft(TenantContext $context, array $data): array;

    public function saveDraft(TenantContext $context, string $id, int $expectedVersion, array $data): array;

    public function publish(TenantContext $context, string $id, int $expectedVersion): KnowledgeSnapshot;

    /** Activate an existing published release; the UI rollback endpoint creates a draft instead. */
    public function rollback(TenantContext $context, string $id): void;

    public function draftEditor(TenantContext $context, string $releaseId): array;

    public function setFact(TenantContext $context, string $releaseId, string $factId, int $expectedVersion, string $text, string $status, string $source): int;

    public function addFact(TenantContext $context, string $releaseId, int $version, string $groupKey, string $text, string $source = 'manual', string $status = 'active'): array;

    public function deleteFact(TenantContext $context, string $releaseId, string $factId, int $version): int;

    public function moveFact(TenantContext $context, string $releaseId, string $factId, int $version, string $groupKey, int $position): int;
}
