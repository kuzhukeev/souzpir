<?php

namespace App\Vision\KnowledgeGrants;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Vision\KnowledgeBridge\GrantAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** Must run inside the caller's transaction; all shared locks survive to its commit. */
final class DatabaseGrantAuthority implements GrantAuthority
{
    public function __construct(private GrantAccess $access) {}

    public function assertActive(TenantContext $tenant, string $grantId, string $releaseId, string $policyChecksum): void
    {
        try {
            $this->verify($tenant, $grantId, $releaseId, $policyChecksum);
        } catch (Throwable) {
            throw new LogicException('Active knowledge grant required.');
        }
    }

    private function verify(TenantContext $tenant, string $grantId, string $releaseId, string $policyChecksum): void
    {
        if (DB::transactionLevel() < 1 || ! Str::isUlid($grantId) || ! Str::isUlid($releaseId)
            || ! preg_match('/^[0-9a-f]{64}$/D', $policyChecksum)) {
            throw new LogicException('Active knowledge grant required.');
        }
        $brand = $this->access->lock($tenant, false);
        if ($brand->active_knowledge_release_id !== $releaseId) {
            throw new LogicException('Active knowledge grant required.');
        }
        $release = DB::table('knowledge_releases')->where('id', $releaseId)->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->where('status', 'published')->sharedLock()->first();
        if (! $release || ! is_string($release->checksum)) {
            throw new LogicException('Active knowledge grant required.');
        }
        $grant = DB::table('vision_knowledge_grants')->where('id', $grantId)->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->where('release_id', $releaseId)->where('purpose', 'vision')
            ->where('data_class', 'knowledge_public')->whereNull('revoked_at')->sharedLock()->first();
        if (! $grant || ! hash_equals($grant->policy_checksum, $policyChecksum)) {
            throw new LogicException('Active knowledge grant required.');
        }
        $settings = json_decode($brand->settings, true, 512, JSON_THROW_ON_ERROR);
        $policy = is_array($settings) ? ($settings['vision_knowledge'] ?? null) : null;
        $allowed = is_array($settings) ? ($settings['ai_data_policy']['knowledge_public'] ?? null) : null;
        if (! is_array($allowed) || ! in_array('any', $allowed, true)) {
            throw new LogicException('Active knowledge grant required.');
        }
        if (! is_array($policy) || count($policy) !== 5 || array_diff(array_keys($policy), ['enabled', 'grant_id', 'release_id', 'allowed_fact_ids', 'data_classes'])
            || ($policy['enabled'] ?? null) !== true || ($policy['grant_id'] ?? null) !== $grantId || ($policy['release_id'] ?? null) !== $releaseId
            || ($policy['data_classes'] ?? null) !== ['knowledge_public'] || ! is_array($policy['allowed_fact_ids'] ?? null)
            || ! array_is_list($policy['allowed_fact_ids']) || count($policy['allowed_fact_ids']) > 1000) {
            throw new LogicException('Active knowledge grant required.');
        }
        $ids = $policy['allowed_fact_ids'];
        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUlid($id)) {
                throw new LogicException('Active knowledge grant required.');
            }
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new LogicException('Active knowledge grant required.');
        }
        sort($ids, SORT_STRING);
        $policy['allowed_fact_ids'] = $ids;
        if (! hash_equals($policyChecksum, CanonicalJson::hash($policy))) {
            throw new LogicException('Active knowledge grant required.');
        }
    }
}
