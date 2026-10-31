<?php

namespace App\Seller\Knowledge;

use App\Models\User;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class DatabaseKnowledgeRepository implements KnowledgeRepository
{
    private const SINGLE = ['conditions' => 'kn_conditions', 'persona' => 'kn_persona', 'policy' => 'kn_policy', 'guard_rules' => 'kn_guard_rules', 'escalation' => 'kn_escalation', 'fewshot' => 'kn_fewshot'];

    private const LIST = ['intents' => 'kn_intents', 'triggers' => 'kn_triggers', 'closed_topics' => 'kn_closed_topics'];

    public function published(TenantContext $context): KnowledgeSnapshot
    {
        $brand = $this->brand($context);
        if (! $brand->active_knowledge_release_id) {
            throw new KnowledgeNotReady;
        }

        return $this->forRelease($context, $brand->active_knowledge_release_id);
    }

    public function forRelease(TenantContext $context, string $id, bool $allowDraft = false): KnowledgeSnapshot
    {
        return DB::transaction(function () use ($context, $id, $allowDraft): KnowledgeSnapshot {
            $this->brand($context);
            $row = $this->release($context, $id, shared: true);
            if ($row->status === 'draft') {
                if (! $allowDraft) {
                    throw new KnowledgeNotReady;
                }
                $this->assertWrite($context);
            } elseif ($row->status !== 'published') {
                throw new KnowledgeNotReady;
            }
            $data = $this->readData($row->id, $row->status === 'draft');
            $checksum = $this->checksum($data);
            if ($row->status === 'published' && ! hash_equals((string) $row->checksum, $checksum)) {
                throw new LogicException('Knowledge checksum mismatch.');
            }

            return new KnowledgeSnapshot($data, $row->id, $checksum);
        });
    }

    /** @return array{id:string,version:int} */
    public function createDraft(TenantContext $context, array $data): array
    {
        $this->assertWrite($context);
        $this->validateData($data);

        return DB::transaction(function () use ($context, $data): array {
            $brand = $this->brand($context, true);
            $this->assertWrite($context, true);
            if (DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->exists()) {
                abort(409, 'Черновик уже существует.');
            }
            $id = (string) Str::ulid();
            DB::table('knowledge_releases')->insert(['id' => $id, 'organization_id' => $context->organizationId, 'brand_id' => $context->brandId, 'status' => 'draft', 'version' => 1, 'created_by' => $context->actorId, 'created_at' => now(), 'updated_at' => now()]);
            $this->writeData($id, $data);
            app(AuditWriter::class)->record($context, new AuditEntry('knowledge.draft.created', 'knowledge_release', $id));

            return ['id' => $id, 'version' => 1];
        });
    }

    /** @return array{id:string,version:int} */
    public function saveDraft(TenantContext $context, string $id, int $expectedVersion, array $data): array
    {
        $this->assertWrite($context);
        $this->validateData($data);

        return DB::transaction(function () use ($context, $id, $expectedVersion, $data): array {
            $this->brand($context, true);
            $this->assertWrite($context, true);
            $row = $this->release($context, $id, true);
            if ($row->status !== 'draft' || $row->version !== $expectedVersion) {
                abort(409, 'Черновик изменён другим пользователем.');
            }
            // Fact edits must use explicit IDs: identical text may have different status or source.
            $existing = [];
            foreach ($this->readData($id, true)['facts'] as $group) {
                if ($group['facts'] !== []) {
                    $existing[$group['id']] = $group['facts'];
                }
            }
            $incoming = [];
            foreach ($data['facts'] as $group) {
                if ($group['facts'] !== []) {
                    $incoming[$group['id']] = $group['facts'];
                }
            }
            ksort($existing);
            ksort($incoming);
            if ($existing !== $incoming) {
                throw new InvalidArgumentException('Edit facts using the fact editor API.');
            }
            $metadata = $this->factMetadata($id);
            $this->deleteData($id);
            $this->writeData($id, $data, $metadata, true);
            DB::table('knowledge_releases')->where('id', $id)->update(['version' => $expectedVersion + 1, 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('knowledge.draft.updated', 'knowledge_release', $id, ['version' => $expectedVersion], ['version' => $expectedVersion + 1]));

            return ['id' => $id, 'version' => $expectedVersion + 1];
        });
    }

    public function publish(TenantContext $context, string $id, int $expectedVersion): KnowledgeSnapshot
    {
        $this->assertWrite($context);

        return DB::transaction(function () use ($context, $id, $expectedVersion): KnowledgeSnapshot {
            $brand = $this->brand($context, true);
            $this->assertWrite($context, true);
            $row = $this->release($context, $id, true);
            if ($row->status !== 'draft' || $row->version !== $expectedVersion) {
                abort(409, 'Черновик изменён другим пользователем.');
            }
            $allData = $this->readData($id, true);
            $this->validateData($allData);
            $metadata = $this->factMetadata($id);
            $data = $this->readData($id);
            $new = (string) Str::ulid();
            $checksum = $this->checksum($data);
            DB::table('knowledge_releases')->insert(['id' => $new, 'organization_id' => $context->organizationId, 'brand_id' => $context->brandId, 'parent_id' => $id, 'status' => 'building', 'version' => $expectedVersion, 'created_by' => $context->actorId, 'created_at' => now(), 'updated_at' => now()]);
            $this->writeData($new, $allData, $metadata);
            DB::table('knowledge_releases')->where('id', $new)->update(['status' => 'published', 'checksum' => $checksum, 'published_at' => now(), 'updated_at' => now()]);
            DB::table('brands')->where('id', $brand->id)->where('organization_id', $context->organizationId)->update(['active_knowledge_release_id' => $new, 'updated_at' => now()]);
            DB::table('knowledge_releases')->where('id', $id)->update(['version' => $expectedVersion + 1, 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('knowledge.release.published', 'knowledge_release', $new, null, ['checksum' => $checksum]));

            return new KnowledgeSnapshot($data, $new, $checksum);
        });
    }

    public function rollback(TenantContext $context, string $id): void
    {
        $this->assertWrite($context);
        DB::transaction(function () use ($context, $id): void {
            $brand = $this->brand($context, true);
            $this->assertWrite($context, true);
            $row = $this->release($context, $id);
            if ($row->status !== 'published') {
                throw new KnowledgeNotReady;
            }
            $this->forRelease($context, $id);
            DB::table('brands')->where('id', $brand->id)->where('organization_id', $context->organizationId)->update(['active_knowledge_release_id' => $id, 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('knowledge.release.activated', 'knowledge_release', $id, ['release_id' => $brand->active_knowledge_release_id], ['release_id' => $id]));
        });
    }

    private function assertWrite(TenantContext $context, bool $lock = false): void
    {
        $this->brand($context);
        $current = app(TenantContext::class);
        if (! $context->actorId || ! $context->membershipId || $current->actorId !== $context->actorId || $current->membershipId !== $context->membershipId) {
            abort(403);
        }
        if ($lock) {
            $membership = DB::table('memberships')->where('id', $context->membershipId)->where('organization_id', $context->organizationId)
                ->where('user_id', $context->actorId)->where('status', 'active')->lockForUpdate()->first();
            if (! $membership) {
                abort(403);
            }
        }
        $user = User::query()->find($context->actorId);
        if (! $user || ! Gate::forUser($user)->allows('seller.knowledge.write')) {
            abort(403);
        }
    }

    private function brand(TenantContext $context, bool $lock = false): object
    {
        $organization = $context->requireOrganizationId();
        $current = app(TenantContext::class);
        if (! $context->brandId || $current->organizationId !== $organization || $current->brandId !== $context->brandId) {
            abort(404);
        }
        if ($lock) {
            $org = DB::table('organizations')->where('id', $organization)->where('status', 'active')->lockForUpdate()->first();
            if (! $org) {
                abort(403);
            }
        }
        $query = DB::table('brands')->where('id', $context->brandId)->where('organization_id', $organization)->where('status', 'active');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? abort(404);
    }

    private function release(TenantContext $context, string $id, bool $lock = false, bool $shared = false): object
    {
        $query = DB::table('knowledge_releases')->where('id', $id)->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId);
        if ($lock) {
            $query->lockForUpdate();
        } elseif ($shared) {
            $query->sharedLock();
        }

        return $query->first() ?? abort(404);
    }

    private function validateData(array $data): void
    {
        new KnowledgeSnapshot($data, 'validation', '');
        foreach (self::LIST as $namespace => $table) {
            if (! array_is_list($data[$namespace])) {
                throw new InvalidArgumentException('Invalid list: '.$namespace);
            }
            $ids = [];
            foreach ($data[$namespace] as $item) {
                if (! is_array($item) || ! is_string($item['id'] ?? null) || $item['id'] === '' || isset($ids[$item['id']])) {
                    throw new InvalidArgumentException('Invalid key: '.$namespace);
                }
                $ids[$item['id']] = true;
            }
        }
        if (! array_is_list($data['facts'])) {
            throw new InvalidArgumentException('Invalid facts.');
        }
        $ids = [];
        foreach ($data['facts'] as $group) {
            if (! is_array($group) || ! is_string($group['id'] ?? null) || $group['id'] === '' || isset($ids[$group['id']]) || ! isset($group['facts']) || ! is_array($group['facts']) || ! array_is_list($group['facts'])) {
                throw new InvalidArgumentException('Invalid fact group.');
            }
            foreach ($group['facts'] as $fact) {
                if (! is_string($fact)) {
                    throw new InvalidArgumentException('Invalid fact.');
                }
            }
            $ids[$group['id']] = true;
        }
        json_encode($data, JSON_THROW_ON_ERROR);
    }

    private function writeData(string $releaseId, array $data, array $metadata = [], bool $reuseIds = false): void
    {
        foreach (self::SINGLE as $namespace => $table) {
            DB::table($table)->insert(['id' => (string) Str::ulid(), 'release_id' => $releaseId, 'data' => json_encode($data[$namespace], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        }
        foreach (self::LIST as $namespace => $table) {
            foreach ($data[$namespace] as $position => $item) {
                DB::table($table)->insert(['id' => (string) Str::ulid(), 'release_id' => $releaseId, 'key' => $item['id'], 'position' => $position, 'data' => json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            }
        }
        foreach ($data['facts'] as $position => $group) {
            $id = (string) Str::ulid();
            $facts = $group['facts'];
            unset($group['facts']);
            DB::table('kn_fact_groups')->insert(['id' => $id, 'release_id' => $releaseId, 'key' => $group['id'], 'position' => $position, 'data' => json_encode($group, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            foreach ($facts as $number => $fact) {
                $old = null;
                if (! empty($metadata[$group['id']][$fact])) {
                    $old = array_shift($metadata[$group['id']][$fact]);
                }
                DB::table('kn_facts')->insert(['id' => $reuseIds && $old ? $old->id : (string) Str::ulid(), 'logical_id' => $old?->logical_id ?? (string) Str::ulid(), 'group_id' => $id, 'position' => $number, 'text' => $fact, 'source' => $old?->source ?? 'manual', 'status' => $old?->status ?? 'active']);
            }
        }
    }

    private function readData(string $id, bool $includeInactive = false): array
    {
        $data = [];
        foreach (self::SINGLE as $namespace => $table) {
            $raw = DB::table($table)->where('release_id', $id)->value('data');
            if ($raw === null) {
                throw new LogicException('Incomplete knowledge namespace: '.$namespace);
            }
            $data[$namespace] = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }
        foreach (self::LIST as $namespace => $table) {
            $data[$namespace] = DB::table($table)->where('release_id', $id)->orderBy('position')->get()->map(fn ($row) => json_decode($row->data, true, 512, JSON_THROW_ON_ERROR))->all();
        }
        $data['facts'] = [];
        foreach (DB::table('kn_fact_groups')->where('release_id', $id)->orderBy('position')->get() as $group) {
            $item = json_decode($group->data, true, 512, JSON_THROW_ON_ERROR);
            $facts = DB::table('kn_facts')->where('group_id', $group->id);
            if (! $includeInactive) {
                $facts->where('status', 'active');
            }
            $item['facts'] = $facts->orderBy('position')->pluck('text')->all();
            $data['facts'][] = $item;
        }

        return $data;
    }

    private function factMetadata(string $releaseId): array
    {
        $metadata = [];
        $rows = DB::table('kn_facts as fact')->join('kn_fact_groups as grp', 'grp.id', '=', 'fact.group_id')
            ->where('grp.release_id', $releaseId)->orderBy('grp.position')->orderBy('fact.position')
            ->select('grp.key', 'fact.id', 'fact.logical_id', 'fact.text', 'fact.source', 'fact.status')->get();
        foreach ($rows as $row) {
            $metadata[$row->key][$row->text][] = $row;
        }

        return $metadata;
    }

    public function setFact(TenantContext $context, string $releaseId, string $factId, int $expectedVersion, string $text, string $status, string $source): int
    {
        $this->assertWrite($context);
        if (! in_array($status, ['active', 'inactive'], true) || ! in_array($source, ['manual', 'import', 'url'], true)) {
            throw new InvalidArgumentException('Invalid fact metadata.');
        }

        return DB::transaction(function () use ($context, $releaseId, $factId, $expectedVersion, $text, $status, $source): int {
            $this->brand($context, true);
            $this->assertWrite($context, true);
            $release = $this->release($context, $releaseId, true);
            if ($release->status !== 'draft' || $release->version !== $expectedVersion) {
                abort(409, 'Черновик изменён другим пользователем.');
            }
            $fact = DB::table('kn_facts as fact')->join('kn_fact_groups as grp', 'grp.id', '=', 'fact.group_id')
                ->where('grp.release_id', $releaseId)->where('fact.id', $factId)->select('fact.*')->first();
            if (! $fact) {
                abort(404);
            }
            DB::table('kn_facts')->where('id', $factId)->update(['text' => $text, 'status' => $status, 'source' => $source]);
            DB::table('knowledge_releases')->where('id', $releaseId)->update(['version' => $expectedVersion + 1, 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('knowledge.fact.updated', 'knowledge_fact', $fact->logical_id, ['status' => $fact->status, 'source' => $fact->source], ['status' => $status, 'source' => $source]));

            return $expectedVersion + 1;
        });
    }

    /** Draft editor returns stable fact IDs, sources and statuses; runtime snapshots intentionally expose only strings. */
    public function draftEditor(TenantContext $context, string $releaseId): array
    {
        return DB::transaction(function () use ($context, $releaseId): array {
            $this->brand($context);
            $release = $this->release($context, $releaseId, shared: true);
            if ($release->status !== 'draft') {
                abort(404);
            }
            $this->assertWrite($context);
            $groups = [];
            foreach (DB::table('kn_fact_groups')->where('release_id', $releaseId)->orderBy('position')->get() as $group) {
                $groups[] = [
                    'key' => $group->key,
                    'facts' => DB::table('kn_facts')->where('group_id', $group->id)->orderBy('position')
                        ->get(['id', 'logical_id', 'text', 'source', 'status'])->map(fn ($row) => (array) $row)->all(),
                ];
            }

            return ['id' => $releaseId, 'version' => $release->version, 'groups' => $groups];
        });
    }

    public function addFact(TenantContext $context, string $releaseId, int $version, string $groupKey, string $text, string $source = 'manual', string $status = 'active'): array
    {
        if (! in_array($status, ['active', 'inactive'], true) || ! in_array($source, ['manual', 'import', 'url'], true)) {
            throw new InvalidArgumentException('Invalid fact metadata.');
        }
        $id = (string) Str::ulid();
        $this->mutateFact($context, $releaseId, $version, 'knowledge.fact.created', $id, function () use ($releaseId, $groupKey, $text, $source, $status, $id): void {
            $group = DB::table('kn_fact_groups')->where('release_id', $releaseId)->where('key', $groupKey)->first() ?? abort(404);
            $max = DB::table('kn_facts')->where('group_id', $group->id)->max('position');
            DB::table('kn_facts')->insert(['id' => $id, 'logical_id' => (string) Str::ulid(), 'group_id' => $group->id, 'position' => $max === null ? 0 : $max + 1, 'text' => $text, 'source' => $source, 'status' => $status]);
        });

        return ['id' => $id, 'version' => $version + 1];
    }

    public function deleteFact(TenantContext $context, string $releaseId, string $factId, int $version): int
    {
        return $this->mutateFact($context, $releaseId, $version, 'knowledge.fact.deleted', $factId, function () use ($releaseId, $factId): void {
            $fact = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')->where('g.release_id', $releaseId)->where('f.id', $factId)->select('f.id')->first() ?? abort(404);
            DB::table('kn_facts')->where('id', $fact->id)->delete();
        });
    }

    public function moveFact(TenantContext $context, string $releaseId, string $factId, int $version, string $groupKey, int $position): int
    {
        return $this->mutateFact($context, $releaseId, $version, 'knowledge.fact.moved', $factId, function () use ($releaseId, $factId, $groupKey, $position): void {
            $fact = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')->where('g.release_id', $releaseId)->where('f.id', $factId)->select('f.*')->first() ?? abort(404);
            $group = DB::table('kn_fact_groups')->where('release_id', $releaseId)->where('key', $groupKey)->first() ?? abort(404);
            $source = DB::table('kn_facts')->where('group_id', $fact->group_id)->where('id', '!=', $factId)->orderBy('position')->pluck('id')->all();
            $destination = $fact->group_id === $group->id ? $source : DB::table('kn_facts')->where('group_id', $group->id)->orderBy('position')->pluck('id')->all();
            if ($position < 0 || $position > count($destination)) {
                throw new InvalidArgumentException('Invalid fact position.');
            }
            array_splice($destination, $position, 0, [$factId]);
            $groups = array_unique([$fact->group_id, $group->id]);
            $max = (int) DB::table('kn_facts')->whereIn('group_id', $groups)->max('position');
            if ($max > 1000000000) {
                throw new LogicException('Fact positions require compaction.');
            }
            $offset = $max + count($source) + count($destination) + 2;
            DB::table('kn_facts')->whereIn('group_id', $groups)->increment('position', $offset);
            DB::table('kn_facts')->where('id', $factId)->update(['group_id' => $group->id, 'position' => 2 * $offset + $max]);
            if ($fact->group_id !== $group->id) {
                foreach ($source as $i => $id) {
                    DB::table('kn_facts')->where('id', $id)->update(['position' => $i]);
                }
            }
            foreach ($destination as $i => $id) {
                DB::table('kn_facts')->where('id', $id)->update(['position' => $i]);
            }
        });
    }

    private function mutateFact(TenantContext $context, string $releaseId, int $version, string $event, string $entityId, \Closure $change): int
    {
        $this->assertWrite($context);

        return DB::transaction(function () use ($context, $releaseId, $version, $event, $entityId, $change): int {
            $this->brand($context, true);
            $this->assertWrite($context, true);
            $release = $this->release($context, $releaseId, true);
            if ($release->status !== 'draft' || $release->version !== $version) {
                abort(409, 'Черновик изменён другим пользователем.');
            }
            $change();
            DB::table('knowledge_releases')->where('id', $releaseId)->update(['version' => $version + 1, 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry($event, 'knowledge_fact', $entityId));

            return $version + 1;
        });
    }

    private function deleteData(string $id): void
    {
        $groups = DB::table('kn_fact_groups')->where('release_id', $id)->pluck('id');
        DB::table('kn_facts')->whereIn('group_id', $groups)->delete();
        DB::table('kn_fact_groups')->where('release_id', $id)->delete();
        foreach ([...array_values(self::SINGLE), ...array_values(self::LIST)] as $table) {
            DB::table($table)->where('release_id', $id)->delete();
        }
    }

    private function checksum(array $data): string
    {
        return hash('sha256', json_encode($this->normalize($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn ($item) => $this->normalize($item), $value);
    }
}
