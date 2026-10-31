<?php

namespace App\Vision\SiteAudit\Knowledge;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Vision\SiteAudit\CrawlerClient;
use App\Vision\SiteAudit\RobotsPolicy;
use App\Vision\SiteAudit\SiteSafety;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/** Crawled text remains a private draft; this service cannot approve or publish knowledge. */
final class SiteKnowledgeImporter
{
    public function __construct(private SiteImportAccess $access, private CrawlerClient $crawler, private SiteKnowledgePage $pages, private SiteDraftStore $drafts) {}

    public function import(#[\SensitiveParameter] TenantContext $tenant, #[\SensitiveParameter] ?string $url = null, int $maxPages = 20): array
    {
        try {
            if ($url !== null) {
                SiteSafety::noQuery($url);
            }

            return $this->importInternal($tenant, $url, $maxPages);
        } catch (Throwable $error) {
            throw SiteSafety::failure($error);
        }
    }

    private function importInternal(#[\SensitiveParameter] TenantContext $tenant, #[\SensitiveParameter] ?string $url, int $maxPages): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Импорт сайта нельзя запускать внутри транзакции.');
        }
        $brand = DB::transaction(fn () => $this->access->lock($tenant));
        if ($maxPages < 1 || $maxPages > 100) {
            throw new InvalidArgumentException('Лимит страниц должен быть от 1 до 100.');
        }
        $root = $this->pages->url($url ?: ($brand->website ?? ''));
        $origin = $this->pages->origin($root);
        if ($origin !== $this->pages->origin($this->pages->url($brand->website ?? '')) || parse_url($root, PHP_URL_QUERY) !== null) {
            throw new InvalidArgumentException('Импорт разрешён только с публичного сайта этого бренда.');
        }
        $deadline = microtime(true) + 120;
        $robots = $this->fetch($origin.'/robots.txt', $origin, null, $deadline);
        $queue = [$this->pages->canonicalUrl($root) => $root];
        $seen = [];
        $rootPriceCaveats = [];
        $result = ['pages' => 0, 'documents_created' => 0, 'versions_created' => 0, 'versions_reused' => 0, 'confirmed' => 0,
            'limited' => false, 'errors' => [], 'sources' => []];
        while ($queue !== [] && count($seen) < $maxPages && microtime(true) < $deadline) {
            $target = array_shift($queue);
            $identity = $this->pages->canonicalUrl($target);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            try {
                $currentBrand = DB::transaction(fn () => $this->access->lock($tenant));
                if ($this->pages->origin($this->pages->url($currentBrand->website ?? '')) !== $origin) {
                    throw new RuntimeException('Сайт бренда изменился во время импорта.');
                }
                if (! RobotsPolicy::allowed($target, $robots)) {
                    throw new RuntimeException('Обход запрещён robots.txt или политика недоступна.');
                }
                $response = $this->fetch($target, $origin, $robots, $deadline);
                if (($response['status'] ?? 0) !== 200 || ! preg_match('~(?:text/html|application/xhtml\+xml)~i', $response['content_type'] ?? '')) {
                    throw new RuntimeException('Страница не вернула HTML с кодом 200.');
                }
                $sourceUrl = $this->pages->url($response['url']);
                $page = $this->pages->extract($response['body'], $sourceUrl);
                if ($target === $root) {
                    $rootPriceCaveats = $page['price_caveats'];
                } elseif ($rootPriceCaveats !== []) {
                    $page['price_caveats'] = array_values(array_unique([...$page['price_caveats'],
                        ...array_map(fn (string $caveat): string => 'Условия на '.$root.': '.$caveat, $rootPriceCaveats)]));
                }
                $page['fingerprint'] = CanonicalJson::hash(['page' => $page['fingerprint'], 'price_caveats' => $page['price_caveats'], 'source_url' => $sourceUrl]);
                $source = $this->drafts->store($tenant, $root, $sourceUrl, $page, $maxPages);
                $result['pages']++;
                foreach (['documents_created', 'versions_created', 'versions_reused', 'confirmed'] as $key) {
                    $result[$key] += $source[$key];
                }
                $result['sources'][] = $source;
                foreach ($page['links'] as $link) {
                    $linkIdentity = $this->pages->canonicalUrl($link);
                    if (! isset($seen[$linkIdentity]) && ! isset($queue[$linkIdentity])) {
                        $queue[$linkIdentity] = $link;
                    }
                }
            } catch (Throwable $error) {
                $result['errors'][] = ['url' => $target, 'message' => SiteSafety::failure($error)->getMessage()];
            }
        }
        $result['limited'] = $queue !== [];

        return $result;
    }

    /** Every redirect is a separate pinned request and remains inside the authorized origin. */
    private function fetch(#[\SensitiveParameter] string $url, #[\SensitiveParameter] string $origin, ?array $robots, float $deadline): array
    {
        $seen = [];
        for ($redirect = 0; $redirect < 5; $redirect++) {
            if (isset($seen[$url]) || $this->pages->origin($url) !== $origin || ($robots !== null && ! RobotsPolicy::allowed($url, $robots))) {
                throw new RuntimeException('Переадресация вышла за разрешённый сайт или правила обхода.');
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('Истекло время импорта сайта.');
            }
            $seen[$url] = true;
            $response = $this->crawler->fetch($url, min(10, $remaining));
            if ($this->pages->url($response['url'] ?? '') !== $this->pages->url($url)) {
                throw new RuntimeException('Обходчик неожиданно изменил адрес страницы.');
            }
            if (! in_array($response['status'] ?? 0, [301, 302, 303, 307, 308], true)) {
                return $response;
            }
            $location = $response['location'] ?? '';
            if ($location === '') {
                throw new RuntimeException('Не указан адрес переадресации.');
            }
            $url = $this->pages->url($url, $location);
        }
        throw new RuntimeException('Слишком много переадресаций.');
    }
}
