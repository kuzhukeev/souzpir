<?php

namespace App\Vision\KnowledgeGrants;

use App\Ai\CanonicalJson;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class GrantRegistry
{
    public function __construct(private GrantAccess $access) {}

    /** @param list<string> $allowedFactIds @return array{id:string,policy_checksum:string} */
    public function issue(TenantContext $tenant, string $releaseId, array $allowedFactIds): array
    {
        if (! Str::isUlid($releaseId) || ! array_is_list($allowedFactIds) || count($allowedFactIds) > 1000) {
            throw new InvalidArgumentException('Invalid knowledge grant.');
        }
        foreach ($allowedFactIds as $id) {
            if (! is_string($id) || ! Str::isUlid($id)) {
                throw new InvalidArgumentException('Invalid knowledge grant.');
            }
        }
        if (count(array_unique($allowedFactIds)) !== count($allowedFactIds)) {
            throw new InvalidArgumentException('Duplicate knowledge fact.');
        }
        sort($allowedFactIds, SORT_STRING);

        return DB::transaction(function () use ($tenant, $releaseId, $allowedFactIds): array {
            $brand = $this->access->lock($tenant, true, 'vision.settings.write');
            if ($brand->active_knowledge_release_id !== $releaseId) {
                throw new LogicException('Active published knowledge release required.');
            }
            $release = DB::table('knowledge_releases')->where('id', $releaseId)->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->where('status', 'published')->sharedLock()->first();
            if (! $release || ! is_string($release->checksum)) {
                throw new LogicException('Active published knowledge release required.');
            }
            $facts = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')
                ->where('g.release_id', $releaseId)->whereIn('f.logical_id', $allowedFactIds)->get(['f.logical_id', 'f.status', 'f.source']);
            if ($facts->count() !== count($allowedFactIds) || $facts->pluck('logical_id')->unique()->count() !== count($allowedFactIds)
                || $facts->contains(fn ($fact) => $fact->status !== 'active' || ! in_array($fact->source, ['manual', 'import', 'url'], true))) {
                throw new LogicException('Only active facts from the selected release can be granted.');
            }
            $existing = DB::table('vision_knowledge_grants')->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->whereNull('revoked_at')->orderBy('id')->lockForUpdate()->get();
            foreach ($existing as $old) {
                $this->revokeLocked($tenant, $old, 'replaced');
            }
            $id = (string) Str::ulid();
            $policy = ['enabled' => true, 'grant_id' => $id, 'release_id' => $releaseId,
                'allowed_fact_ids' => $allowedFactIds, 'data_classes' => ['knowledge_public']];
            $checksum = CanonicalJson::hash($policy);
            $settings = json_decode($brand->settings, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($settings)) {
                throw new LogicException('Invalid brand settings.');
            }
            $settings['vision_knowledge'] = $policy;
            DB::table('brands')->where('id', $tenant->brandId)->where('organization_id', $tenant->organizationId)
                ->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            DB::table('vision_knowledge_grants')->insert(['id' => $id, 'organization_id' => $tenant->organizationId,
                'brand_id' => $tenant->brandId, 'release_id' => $releaseId, 'purpose' => 'vision',
                'data_class' => 'knowledge_public', 'policy_checksum' => $checksum,
                'issued_by' => $tenant->actorId, 'created_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('vision.knowledge.grant.issued', 'vision_knowledge_grant', $id,
                null, ['release_id' => $releaseId, 'policy_checksum' => $checksum]));

            return ['id' => $id, 'policy_checksum' => $checksum];
        });
    }

    /** Explicit user revocation; previously revoked IDs remain revoked forever. */
    public function revoke(TenantContext $tenant, string $grantId): bool
    {
        if (! Str::isUlid($grantId)) {
            throw new InvalidArgumentException('Invalid knowledge grant.');
        }

        return DB::transaction(function () use ($tenant, $grantId): bool {
            $brand = $this->access->lock($tenant, true, 'vision.settings.write');
            $reference = DB::table('vision_knowledge_grants')->where('id', $grantId)->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->first(['release_id']);
            if (! $reference || ! DB::table('knowledge_releases')->where('id', $reference->release_id)
                ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->sharedLock()->first()) {
                throw new LogicException('Knowledge grant not found.');
            }
            $grant = DB::table('vision_knowledge_grants')->where('id', $grantId)->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->where('release_id', $reference->release_id)->lockForUpdate()->first();
            if (! $grant) {
                throw new LogicException('Knowledge grant not found.');
            }
            if ($grant->revoked_at !== null) {
                return false;
            }
            $this->revokeLocked($tenant, $grant, 'manual');
            $settings = json_decode($brand->settings, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($settings)) {
                throw new LogicException('Invalid brand settings.');
            }
            if (($settings['vision_knowledge']['grant_id'] ?? null) === $grantId) {
                unset($settings['vision_knowledge']);
                DB::table('brands')->where('id', $tenant->brandId)->where('organization_id', $tenant->organizationId)
                    ->update(['settings' => json_encode((object) $settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }

            return true;
        });
    }

    private function revokeLocked(TenantContext $tenant, object $grant, string $reason): void
    {
        $changed = DB::table('vision_knowledge_grants')->where('id', $grant->id)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_by' => $tenant->actorId, 'revocation_reason' => $reason]);
        if ($changed !== 1) {
            throw new LogicException('Knowledge grant changed concurrently.');
        }
        app(AuditWriter::class)->record($tenant, new AuditEntry('vision.knowledge.grant.revoked', 'vision_knowledge_grant', $grant->id,
            ['release_id' => $grant->release_id], ['reason' => $reason]));
    }
}
