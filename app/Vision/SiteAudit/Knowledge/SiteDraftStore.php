<?php

namespace App\Vision\SiteAudit\Knowledge;

use App\Ai\CanonicalJson;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/** Private unreviewed source material, never a published knowledge release. */
final class SiteDraftStore
{
    private const ACTION = 'vision.site_source.draft_stored';

    public function __construct(private SiteImportAccess $access, private SiteKnowledgePage $pages, private AuditWriter $audit) {}

    public function store(TenantContext $tenant, string $root, string $url, array $page, int $maxPages): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Хранилище источников должно завершать собственную транзакцию.');
        }
        $this->validatePage($page);
        $root = $this->pages->url($root);
        $url = $this->pages->url($url);
        if ($this->pages->origin($root) !== $this->pages->origin($url) || parse_url($root, PHP_URL_QUERY) !== null || $maxPages < 1 || $maxPages > 100) {
            throw new InvalidArgumentException('Источник не принадлежит разрешённому сайту.');
        }
        $page['content_hash'] = $this->contentHash($page, $url);

        return $this->locked($tenant, $url, function (string $directory) use ($tenant, $root, $url, $page, $maxPages): array {
            $manifest = $directory.'/manifest.json';
            $before = is_file($manifest) ? File::get($manifest) : null;
            $written = null;
            try {
                return DB::transaction(function () use ($tenant, $root, $url, $page, $maxPages, $directory, $manifest, $before, &$written): array {
                    $brand = $this->access->lock($tenant);
                    if ($this->pages->origin($this->pages->url($brand->website ?? '')) !== $this->pages->origin($root)) {
                        throw new LogicException('Сайт бренда изменился во время импорта.');
                    }
                    $state = $before === null ? null : json_decode($before, true, flags: JSON_THROW_ON_ERROR);
                    $latest = $this->latest($tenant, $directory, $state);
                    if ($latest && $latest['fingerprint'] === $page['fingerprint'] && $latest['content_hash'] === $page['content_hash']) {
                        return $this->result($url, $latest, false, true);
                    }
                    $documentId = $latest['document_id'] ?? (string) Str::ulid();
                    $revision = [
                        'schema_version' => 1, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                        'document_id' => $documentId, 'version_id' => (string) Str::ulid(),
                        'version' => ($latest['version'] ?? 0) + 1, 'status' => 'draft',
                        'source_url' => $url, 'root_url' => $root, 'title' => $page['title'],
                        'content_text' => $page['text'], 'source_hash' => hash('sha256', $page['text']),
                        'fingerprint' => $page['fingerprint'], 'content_hash' => $page['content_hash'], 'previous_fingerprint' => $latest['fingerprint'] ?? null,
                        'price_caveats' => $page['price_caveats'], 'max_pages' => $maxPages,
                        'created_by' => $tenant->actorId, 'fetched_at' => now()->toIso8601String(),
                        'audit_event_id' => (string) Str::ulid(),
                    ];
                    $written = $directory.'/'.$revision['version_id'].'.json';
                    File::replace($written, json_encode($revision, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 0600);
                    // Audit is the commit marker. Uncommitted/orphan files are not returned or reused.
                    $this->audit->record($tenant, new AuditEntry(
                        action: self::ACTION, subjectType: 'site_source', subjectId: $documentId,
                        before: $latest ? ['version' => $latest['version'], 'fingerprint' => $latest['fingerprint']] : null,
                        after: ['version_id' => $revision['version_id'], 'version' => $revision['version'], 'fingerprint' => $revision['fingerprint'], 'content_hash' => $revision['content_hash']],
                        eventId: $revision['audit_event_id'],
                    ));
                    $versions = $state['versions'] ?? [];
                    $versions[] = $revision['version_id'];
                    File::replace($manifest, json_encode(['versions' => $versions], JSON_THROW_ON_ERROR), 0600);

                    return $this->result($url, $revision, $latest === null, false);
                });
            } catch (Throwable $error) {
                if ($before === null) {
                    File::delete($manifest);
                } else {
                    File::replace($manifest, $before, 0600);
                }
                if ($written !== null) {
                    File::delete($written);
                }
                throw $error;
            }
        });
    }

    public function read(TenantContext $tenant, string $url): ?array
    {
        return $this->locked($tenant, $url, function (string $directory) use ($tenant): ?array {
            return DB::transaction(function () use ($tenant, $directory): ?array {
                $this->access->lock($tenant);
                $manifest = $directory.'/manifest.json';

                return is_file($manifest) ? $this->latest($tenant, $directory, json_decode(File::get($manifest), true, flags: JSON_THROW_ON_ERROR)) : null;
            });
        });
    }

    private function latest(TenantContext $tenant, string $directory, ?array $state): ?array
    {
        foreach (array_reverse($state['versions'] ?? []) as $id) {
            if (! is_string($id) || ! Str::isUlid($id)) {
                throw new RuntimeException('Повреждён список версий источника.');
            }
            $path = $directory.'/'.$id.'.json';
            if (! is_file($path)) {
                continue;
            }
            $revision = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
            if (($revision['organization_id'] ?? null) !== $tenant->organizationId || ($revision['brand_id'] ?? null) !== $tenant->brandId) {
                throw new RuntimeException('Нарушена изоляция источника.');
            }
            $committed = DB::table('audit_logs')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('action', self::ACTION)->where('subject_type', 'site_source')->where('subject_id', $revision['document_id'])
                ->where('event_id', $revision['audit_event_id'])->value('after');
            if ($committed !== null) {
                $marker = json_decode($committed, true, flags: JSON_THROW_ON_ERROR);
                $page = ['title' => $revision['title'], 'text' => $revision['content_text'], 'price_caveats' => $revision['price_caveats'], 'fingerprint' => $revision['fingerprint']];
                if ($revision['version_id'] !== $id || ($marker['version_id'] ?? null) !== $id
                    || ($marker['content_hash'] ?? null) !== $this->contentHash($page, $revision['source_url'])
                    || $revision['content_hash'] !== $marker['content_hash']) {
                    throw new RuntimeException('Нарушена целостность версии источника.');
                }

                return $revision;
            }
        }

        return null;
    }

    private function locked(TenantContext $tenant, string $url, callable $operation): mixed
    {
        // Authorize before creating even an empty private directory or lock file.
        DB::transaction(fn () => $this->access->lock($tenant));
        $key = hash('sha256', $this->pages->canonicalUrl($url));
        $directory = storage_path('app/private/vision/site-audit/'.$tenant->organizationId.'/'.$tenant->brandId.'/'.$key);
        File::ensureDirectoryExists($directory, 0700, true);
        $handle = fopen($directory.'/.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('Не удалось заблокировать источник.');
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Источник уже сохраняется другим импортом.');
            }

            return $operation($directory);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function validatePage(array $page): void
    {
        if (! is_string($page['title'] ?? null) || ! mb_check_encoding($page['title'], 'UTF-8') || mb_strlen($page['title']) > 180
            || ! is_string($page['text'] ?? null) || ! mb_check_encoding($page['text'], 'UTF-8')
            || mb_strlen($page['text']) < 10 || mb_strlen($page['text']) > 500000
            || ! is_string($page['fingerprint'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $page['fingerprint'])
            || ! is_array($page['price_caveats'] ?? null) || ! array_is_list($page['price_caveats'])) {
            throw new InvalidArgumentException('Некорректный черновик источника.');
        }
        foreach ($page['price_caveats'] as $caveat) {
            if (! is_string($caveat) || ! mb_check_encoding($caveat, 'UTF-8')) {
                throw new InvalidArgumentException('Некорректные условия источника.');
            }
        }
        if (strlen(json_encode($page['price_caveats'], JSON_THROW_ON_ERROR)) > 6000000) {
            throw new InvalidArgumentException('Превышен лимит условий источника.');
        }
    }

    private function contentHash(array $page, string $url): string
    {
        return CanonicalJson::hash(['title' => $page['title'], 'text' => $page['text'], 'price_caveats' => $page['price_caveats'],
            'source_url' => $url, 'fingerprint' => $page['fingerprint']]);
    }

    private function result(string $url, array $revision, bool $created, bool $reused): array
    {
        return ['url' => $url, 'document_id' => $revision['document_id'], 'version_id' => $revision['version_id'],
            'proposal_id' => null, 'documents_created' => (int) $created, 'versions_created' => (int) ! $reused,
            'versions_reused' => (int) $reused, 'confirmed' => 0];
    }
}
