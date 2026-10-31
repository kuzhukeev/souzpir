<?php

namespace App\Vision\KnowledgeBridge;

use App\Ai\CanonicalJson;
use App\Models\Membership;
use App\Privacy\ContactBoundary;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Shell\Organizations\ModuleEntitlements;
use App\Tenancy\TenantContext;
use App\Vision\Core\Profiles\ProfileAccess;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** Builds and revalidates the whole public profile; caller-supplied content is never authority. */
final class PublishedKnowledgeBridge implements KnowledgeGate
{
    public function __construct(private KnowledgeRepository $knowledge, private ContactBoundary $contacts, private GrantAuthority $grants) {}

    /** Build inside the measurement-creation transaction before inserting the frozen profile. */
    public function buildProfile(TenantContext $tenant): array
    {
        try {
            if (DB::connection()->transactionLevel() < 1) {
                throw new LogicException;
            }

            $hasDeclared = DB::table('brand_profiles')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->exists();
            if ($hasDeclared && $tenant->source === 'session') {
                return app(ProfileAccess::class)->run(request(), function (TenantContext $checked) use ($tenant): array {
                    if ($checked->organizationId !== $tenant->organizationId || $checked->brandId !== $tenant->brandId
                        || $checked->actorId !== $tenant->actorId || $checked->membershipId !== $tenant->membershipId) {
                        throw new LogicException;
                    }

                    return $this->snapshotWithDeclared($tenant);
                });
            }
            $profile = $this->snapshotWithDeclared($tenant);
            // A submission racing the initial absence check must be retried with session authorization.
            if (! $hasDeclared && $tenant->source === 'session' && array_key_exists('self_declared', $profile)) {
                throw new LogicException;
            }

            return $profile;
        } catch (Throwable) {
            throw new LogicException('The approved knowledge profile is unavailable.');
        }
    }

    public function assertAllowed(TenantContext $tenant, string $measurementId, #[\SensitiveParameter] array $frozenProfile): void
    {
        try {
            if (! Str::isUlid($measurementId) || ! Schema::hasTable('measurements')) {
                throw new LogicException;
            }
            DB::transaction(function () use ($tenant, $measurementId, $frozenProfile): void {
                $profile = $this->project($tenant);
                $row = DB::table('measurements')->where('id', $measurementId)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->sharedLock()->first(['profile_snapshot', 'profile_checksum']);
                if (! $row || ! is_string($row->profile_snapshot) || ! is_string($row->profile_checksum)) {
                    throw new LogicException;
                }
                $persisted = json_decode($row->profile_snapshot, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($persisted) || ! hash_equals($row->profile_checksum, CanonicalJson::hash($persisted))
                    || ! hash_equals(CanonicalJson::hash($persisted), CanonicalJson::hash($frozenProfile))) {
                    throw new LogicException;
                }
                $published = $persisted;
                $declared = $published['self_declared'] ?? null;
                unset($published['self_declared']);
                if (! hash_equals(CanonicalJson::hash($profile), CanonicalJson::hash($published))) {
                    throw new LogicException;
                }
                if (array_key_exists('self_declared', $persisted)) {
                    if (! is_array($declared) || ! is_string($declared['profile_id'] ?? null)) {
                        throw new LogicException;
                    }
                    $historical = $this->selfDeclared($tenant, $declared['profile_id']);
                    if (! hash_equals(CanonicalJson::hash($declared), CanonicalJson::hash($historical))) {
                        throw new LogicException;
                    }
                    // The current evaluator sends the full snapshot as knowledge_public.
                    // Refuse release until integration removes self-declared content from all outbound payloads.
                    throw new LogicException;
                }
            });
        } catch (Throwable) {
            // Never expose content, contacts or tenant data in a log or exception chain.
            throw new LogicException('The approved knowledge profile is unavailable.');
        }
    }

    private function snapshotWithDeclared(TenantContext $tenant): array
    {
        $profile = $this->project($tenant);
        $declared = $this->selfDeclared($tenant);
        if ($declared !== null) {
            $profile['self_declared'] = $declared;
        }

        return $profile;
    }

    /** Only called internally after the bridge authorization and grant checks. */
    private function selfDeclared(TenantContext $tenant, ?string $profileId = null): ?array
    {
        $current = app(TenantContext::class);
        if (DB::connection()->transactionLevel() < 1 || ! $tenant->organizationId || ! $tenant->brandId
            || $tenant->organizationId !== $current->organizationId || $tenant->brandId !== $current->brandId
            || $tenant->actorId !== $current->actorId || $tenant->membershipId !== $current->membershipId
            || $tenant->source !== $current->source || $tenant->source === 'public'
            || ($profileId !== null && ! Str::isUlid($profileId))) {
            throw new LogicException('Vision profile is unavailable.');
        }
        $query = DB::table('brand_profiles')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
        $row = $profileId === null ? $query->orderByDesc('version')->first() : $query->where('id', $profileId)->first();
        if (! $row) {
            if ($profileId !== null) {
                throw new LogicException('Vision profile is unavailable.');
            }

            return null;
        }
        $answers = json_decode($row->answers, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($answers) || ! is_string($row->checksum) || ! hash_equals($row->checksum, CanonicalJson::hash($answers))) {
            throw new LogicException('Vision profile is unavailable.');
        }

        return ['profile_id' => $row->id, 'version' => (int) $row->version, 'checksum' => $row->checksum,
            ...SelfDeclaredProfile::projectAnswers($answers)];
    }

    private function project(TenantContext $tenant): array
    {
        $current = app(TenantContext::class);
        if (! $tenant->organizationId || ! $tenant->brandId || $current->organizationId !== $tenant->organizationId || $current->brandId !== $tenant->brandId || $current->actorId !== $tenant->actorId || $current->membershipId !== $tenant->membershipId || $current->source !== $tenant->source || $tenant->source === 'public') {
            throw new LogicException;
        }
        // Same lock order as E3-01 publication: organization → brand → release.
        $org = DB::table('organizations')->where('id', $tenant->organizationId)->where('status', 'active')->sharedLock()->first();
        $brand = DB::table('brands')->where('id', $tenant->brandId)->where('organization_id', $tenant->organizationId)->where('status', 'active')->sharedLock()->first();
        if (! $org || ! $brand || ! is_string($brand->active_knowledge_release_id) || ! is_string($brand->name) || $brand->name === '' || mb_strlen($brand->name) > 200) {
            throw new LogicException;
        }
        if (($tenant->source !== 'job' || $tenant->actorId !== null || $tenant->membershipId !== null)) {
            if (! $tenant->actorId || ! $tenant->membershipId) {
                throw new LogicException;
            }
            $member = Membership::withoutGlobalScopes()->whereKey($tenant->membershipId)->where('organization_id', $tenant->organizationId)->where('user_id', $tenant->actorId)->where('status', 'active')->sharedLock()->first();
            if (! $member || ! $member->permitsBrand($tenant->brandId)) {
                throw new LogicException;
            }
        }
        if (! app(ModuleEntitlements::class)->enabled($tenant, 'vision')) {
            throw new LogicException;
        }
        $settings = json_decode($brand->settings, true, 64, JSON_THROW_ON_ERROR);
        $policy = $settings['vision_knowledge'] ?? null;
        if (! is_array($policy) || array_diff(array_keys($policy), ['enabled', 'grant_id', 'release_id', 'allowed_fact_ids', 'data_classes']) || count($policy) !== 5
            || $policy['enabled'] !== true || ! is_string($policy['grant_id']) || ! Str::isUlid($policy['grant_id']) || $policy['release_id'] !== $brand->active_knowledge_release_id
            || ($policy['data_classes'] ?? null) !== ['knowledge_public'] || ! is_array($policy['allowed_fact_ids'] ?? null)
            || ! array_is_list($policy['allowed_fact_ids']) || count($policy['allowed_fact_ids']) > 1000
            || ! is_array($settings['ai_data_policy']['knowledge_public'] ?? null) || ! in_array('any', $settings['ai_data_policy']['knowledge_public'], true)) {
            throw new LogicException;
        }
        $ids = $policy['allowed_fact_ids'];
        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUlid($id)) {
                throw new LogicException;
            }
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new LogicException;
        }
        sort($ids, SORT_STRING);
        $aliases = $settings['onboarding']['aliases'] ?? [];
        if (! is_array($aliases) || ! array_is_list($aliases) || count($aliases) > 50) {
            throw new LogicException;
        }
        foreach ($aliases as $alias) {
            if (! is_string($alias) || $alias === '' || mb_strlen($alias) > 200) {
                throw new LogicException;
            }
        }
        $release = DB::table('knowledge_releases')->where('id', $brand->active_knowledge_release_id)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'published')->sharedLock()->first();
        if (! $release || ! is_string($release->checksum)) {
            throw new LogicException;
        }
        $snapshot = $this->knowledge->forRelease($tenant, $release->id);
        if (! hash_equals($release->checksum, $snapshot->checksum())) {
            throw new LogicException;
        }
        $groups = [];
        foreach ($snapshot->all('facts') as $group) {
            $groups[$group['id']] = $group['facts'];
        }
        $rows = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')->where('g.release_id', $release->id)->whereIn('f.logical_id', $ids)->orderBy('g.position')->orderBy('f.position')->get(['f.logical_id', 'f.text', 'f.source', 'f.status', 'g.key']);
        if ($rows->count() !== count($ids) || $rows->pluck('logical_id')->unique()->count() !== count($ids)) {
            throw new LogicException;
        }
        $facts = [];
        foreach ($rows as $row) {
            if ($row->status !== 'active' || ! in_array($row->source, ['manual', 'import', 'url'], true) || ! is_string($row->text) || $row->text === '' || mb_strlen($row->text) > 4000 || ! is_string($row->key) || ! isset($groups[$row->key]) || ! in_array($row->text, $groups[$row->key], true)) {
                throw new LogicException;
            }
            $facts[] = ['id' => $row->logical_id, 'topic' => 'knowledge_fact', 'value' => $row->text, 'verifiable' => false, 'source' => $row->source];
        }
        $policyChecksum = CanonicalJson::hash(['enabled' => true, 'grant_id' => $policy['grant_id'], 'release_id' => $release->id, 'allowed_fact_ids' => $ids, 'data_classes' => ['knowledge_public']]);
        $this->grants->assertActive($tenant, $policy['grant_id'], $release->id, $policyChecksum);
        $profile = ['name' => $brand->name, 'aliases' => $aliases, 'facts' => $facts, 'knowledge' => [
            'release_id' => $release->id, 'checksum' => $release->checksum, 'data_classes' => ['knowledge_public'],
            'grant_id' => $policy['grant_id'], 'policy_checksum' => $policyChecksum,
        ]];
        if (strlen(json_encode($profile, JSON_THROW_ON_ERROR)) > 100000) {
            throw new LogicException;
        }
        $this->contacts->assertSafe(['messages' => [['role' => 'user', 'content' => $profile]]]);

        return $profile;
    }
}
