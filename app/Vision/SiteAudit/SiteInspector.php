<?php

namespace App\Vision\SiteAudit;

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

class SiteInspector
{
    private array $robotsCache = [];

    private int $requests = 0;

    private float $deadline = 0;

    public function __construct(private CrawlerClient $crawler) {}

    public function inspect(#[\SensitiveParameter] string $url, ?int $pageLimit = null, ?callable $progress = null): array
    {
        try {
            SiteSafety::noQuery($url);
            $uri = new Uri($url);
            if (! in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getHost() === '' || $uri->getUserInfo() !== '') {
                throw new \InvalidArgumentException('Некорректный адрес сайта.');
            }

            return $this->inspectInternal((string) $uri->withFragment(''), $pageLimit, $progress);
        } catch (\Throwable $error) {
            throw SiteSafety::failure($error);
        }
    }

    private function inspectInternal(#[\SensitiveParameter] string $url, ?int $pageLimit, ?callable $progress): array
    {
        $pageLimit = max(1, min(50, $pageLimit ?? config('features.E8-01B.defaults.crawl_pages', 5)));
        $this->robotsCache = [];
        $this->requests = 0;
        $this->deadline = $this->clock() + 60;
        $issues = [];
        $robots = $this->robots($url);
        try {
            $root = $this->fetchAllowed($url);
        } catch (\Throwable) {
            $root = ['url' => $url, 'body' => '', 'status' => 0, 'fetched_at' => now()->toIso8601String()];
            $issues[] = 'Главная страница не загружена: правила обхода недоступны, запрещают путь либо безопасная загрузка не выполнена. Отсутствие данных не означает плохое качество сайта.';
        }
        $this->checkDeadline();
        $host = parse_url($root['url'], PHP_URL_HOST);
        $origin = $this->origin($root['url']);
        $pages = $root['status'] ? [$root] : [];
        $robots = $this->robots($root['url']);
        $sitemap = null;
        if ($root['status']) {
            try {
                $sitemap = $this->fetchAllowed($origin.'/sitemap.xml');
            } catch (\Throwable) {
                $issues[] = 'sitemap.xml не удалось проверить.';
            }
        }
        $this->checkDeadline();
        $links = $this->links($root['body'], $root['url']);
        $selectedLinks = array_slice($links, 0, $pageLimit - 1);
        $totalPages = 1 + count($selectedLinks);
        $processed = 1;
        if ($progress) {
            $progress($processed, $totalPages);
        }
        foreach ($selectedLinks as $link) {
            $this->checkDeadline();
            if (! $this->allowed($link, $robots)) {
                $issues[] = 'Путь исключён robots.txt: '.parse_url($link, PHP_URL_PATH);
                if ($progress) {
                    $progress(++$processed, $totalPages);
                }

                continue;
            }
            try {
                $pages[] = $this->fetchAllowed($link);
            } catch (\Throwable) {
                $issues[] = 'Страница недоступна для разрешённого обхода: '.$link;
            }
            if ($progress) {
                $progress(++$processed, $totalPages);
            }
        }
        $this->checkDeadline();
        $texts = [];
        $schemas = [];
        foreach ($pages as $page) {
            $this->checkDeadline();
            $dom = $this->dom($page['body']);
            $xp = new \DOMXPath($dom);
            $commercial = $this->commercialDom($dom);
            $commercialXp = new \DOMXPath($commercial);
            $pageTypes = [];
            $pageFacts = [];
            $collectTypes = function (mixed $node) use (&$collectTypes, &$pageTypes, &$pageFacts): void {
                if (! is_array($node)) {
                    return;
                }
                foreach ((array) ($node['@type'] ?? []) as $type) {
                    if (is_string($type) && in_array($type, ['ContactPage', 'AboutPage', 'CollectionPage', 'ItemList', 'Product', 'Service'], true)) {
                        $pageTypes[] = $type;
                        foreach (['name' => 'Название', 'description' => 'Описание'] as $field => $label) {
                            if (is_string($node[$field] ?? null) && trim($node[$field]) !== '' && count($pageFacts) < 10) {
                                $pageFacts[] = ['label' => $label, 'value' => mb_substr(trim($node[$field]), 0, 300)];
                            }
                        }
                    }
                }
                foreach ($node as $child) {
                    if (is_array($child)) {
                        $collectTypes($child);
                    }
                }
            };
            foreach ($commercialXp->query('//script[@type="application/ld+json"]') as $script) {
                if ($page['status'] < 200 || $page['status'] >= 300) {
                    continue;
                }
                $value = json_decode($script->textContent, true);
                if (is_array($value)) {
                    $schemas[] = ['data' => $value, 'page' => $page];
                    $collectTypes($value);
                }
            }
            foreach ($xp->query('//script|//style|//noscript|//svg') as $node) {
                $node->parentNode?->removeChild($node);
            }
            foreach ($commercialXp->query('//script|//style|//noscript|//svg') as $node) {
                $node->parentNode?->removeChild($node);
            }
            $references = [];
            foreach ($commercialXp->query('//a[@href]') as $anchor) {
                try {
                    $target = $this->resolve($page['url'], $anchor->getAttribute('href'));
                    $references[] = ['url' => $target, 'label' => mb_substr(trim($anchor->textContent), 0, 200),
                        'offer_card' => $this->offerCard($anchor)];
                } catch (\Throwable) {
                }
            }
            $headings = [];
            foreach ($commercialXp->query('//h1|//h2|//h3') as $heading) {
                $headings[] = mb_substr(preg_replace('/\s+/u', ' ', trim($heading->textContent)), 0, 200);
            }
            $texts[] = ['url' => $page['url'], 'text' => mb_substr(preg_replace('/\s+/u', ' ', trim($dom->textContent)), 0, 50000),
                'commercial_text' => mb_substr(preg_replace('/\s+/u', ' ', trim($commercial->textContent)), 0, 50000),
                'fulfilment_evidence' => $this->fulfilmentEvidence($commercial),
                'primary_heading' => trim($commercialXp->query('//h1')->item(0)?->textContent ?? ''),
                'headings' => $headings, 'references' => $references, 'structured_types' => array_values(array_unique($pageTypes)), 'structured_facts' => $pageFacts, 'observed_at' => $page['fetched_at'], 'status' => $page['status']];
        }
        $criteria = $this->criteria($root, $texts, $schemas, $links, $robots, $sitemap);
        $llms = null;
        if ($root['status']) {
            try {
                $llms = $this->fetchAllowed($origin.'/llms.txt');
            } catch (\Throwable) {
            }
        }
        $aiAccess = $this->aiAccess($root['url'], $robots, $llms, $sitemap);
        $aiAccess['captcha'] = $this->captchaIndicators($pages);

        $this->checkDeadline();

        return ['url' => $url, 'final_url' => $root['url'], 'readiness' => Metrics::readiness($criteria), 'criteria' => $criteria, 'ai_access' => $aiAccess, 'issues' => $issues, 'pages' => $texts, 'crawled_pages' => count($pages), 'scope' => 'Публичный HTML, до '.$pageLimit.' страниц. Без исполнения JavaScript. Автоматически подтверждаются только приведённые свидетельства; семантическая проверка и AI-поиск указаны отдельно.'];
    }

    /** Report only visible markup markers, never claim that protection is absent. */
    private function captchaIndicators(array $pages): array
    {
        $found = [];
        foreach ($pages as $page) {
            $body = $page['body'] ?? '';
            if (! is_string($body)) {
                continue;
            }
            foreach (['reCAPTCHA' => '~<(?:script|iframe|div)[^>]+(?:recaptcha|g-recaptcha)[^>]*>~i',
                'hCaptcha' => '~<(?:script|iframe|div)[^>]+(?:hcaptcha|h-captcha)[^>]*>~i',
                'Cloudflare Turnstile' => '~<(?:script|iframe|div)[^>]+(?:challenges\.cloudflare\.com/turnstile|cf-turnstile)[^>]*>~i'] as $name => $pattern) {
                if (preg_match($pattern, $body)) {
                    $found[$name] = true;
                }
            }
        }

        return ['indicators' => array_keys($found), 'pages_observed' => count($pages)];
    }

    private function aiAccess(string $url, ?array $robots, ?array $llms, ?array $sitemap): array
    {
        $bots = [];
        foreach ($this->aiAgents() as $agent) {
            $bots[] = $agent + ['allowed' => $this->agentRule($url, $robots, $agent['user_agent'])];
        }
        $known = count(array_filter($bots, fn (array $bot): bool => $bot['allowed'] !== null));
        $describe = fn (?array $response): array => ['status' => $response['status'] ?? null, 'url' => $response['url'] ?? null, 'bytes' => $response ? strlen($response['body']) : null, 'observed_at' => $response['fetched_at'] ?? null];

        return ['robots' => $describe($robots), 'llms' => $describe($llms), 'llms_txt' => $describe($llms), 'has_sitemap_link' => $llms && $llms['status'] === 200 ? (bool) preg_match('~https?://[^\s<>]+sitemap[^\s<>]*|\]\([^)]*sitemap[^)]*\)~i', $llms['body']) : null, 'sitemap' => $describe($sitemap) + ['valid_xml' => $this->isSitemap($sitemap)], 'bots' => $bots,
            'requests' => $this->requests, 'request_limit' => 10, 'reference' => 'https://developers.openai.com/api/docs/bots',
            'references' => array_values(array_unique(array_filter(array_column($bots, 'reference')))), 'catalog_reviewed_at' => '2026-10-18', 'checked_url' => $url,
            'coverage' => ['total' => count($bots), 'known' => $known, 'unknown' => count($bots) - $known],
            'note' => 'Проверены правила robots.txt для указанного адреса, а не всех страниц или фактических запросов роботов. Охват показывает известность правил, не доступность сайта. Обучение, поиск и загрузка по запросу пользователя различаются; llms.txt сам по себе не доказывает доступность или видимость.'];
    }

    /** @return list<array{user_agent: string, role: string, kind: string, reference: ?string, note: string}> */
    private function aiAgents(): array
    {
        $openai = 'https://developers.openai.com/api/docs/bots';
        $perplexity = 'https://docs.perplexity.ai/docs/resources/perplexity-crawlers';
        $anthropic = 'https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler';
        $google = 'https://developers.google.com/crawling/docs/crawlers-fetchers/google-common-crawlers';
        $yandex = 'https://yandex.com/support/webmaster/en/robot-workings/check-yandex-robots.html';

        return [
            ['user_agent' => 'OAI-SearchBot', 'role' => 'search', 'kind' => 'crawler', 'reference' => $openai,
                'note' => 'Поисковый обход для ChatGPT. Разрешение в robots.txt не подтверждает индексацию или упоминание сайта.'],
            ['user_agent' => 'GPTBot', 'role' => 'training', 'kind' => 'crawler', 'reference' => $openai,
                'note' => 'Сбор материалов для обучения моделей. Запрет обучения не равен запрету поиска.'],
            ['user_agent' => 'ChatGPT-User', 'role' => 'user_fetch', 'kind' => 'user_fetcher', 'reference' => $openai,
                'note' => 'Загрузка по запросу пользователя; правила robots.txt могут не применяться. Не управляет поисковой индексацией.'],
            ['user_agent' => 'PerplexityBot', 'role' => 'search', 'kind' => 'crawler', 'reference' => $perplexity,
                'note' => 'Поиск и ссылки в Perplexity; по документации не используется для обучения базовых моделей.'],
            ['user_agent' => 'Perplexity-User', 'role' => 'user_fetch', 'kind' => 'user_fetcher', 'reference' => $perplexity,
                'note' => 'Загрузка по запросу пользователя; по документации обычно не применяет robots.txt. Показано правило сайта, не фактическая блокировка.'],
            ['user_agent' => 'ClaudeBot', 'role' => 'training', 'kind' => 'crawler', 'reference' => $anthropic,
                'note' => 'Сбор материалов для возможного обучения моделей; отдельно от поиска Claude.'],
            ['user_agent' => 'Claude-SearchBot', 'role' => 'search', 'kind' => 'crawler', 'reference' => $anthropic,
                'note' => 'Поисковый обход Claude; фактическая индексация этой проверкой не подтверждается.'],
            ['user_agent' => 'Claude-User', 'role' => 'user_fetch', 'kind' => 'user_fetcher', 'reference' => $anthropic,
                'note' => 'Получение страниц по запросу пользователя Claude; Anthropic документирует соблюдение robots.txt.'],
            ['user_agent' => 'Googlebot', 'role' => 'search', 'kind' => 'crawler', 'reference' => $google,
                'note' => 'Индексация Google Search. Проверка правила не является измерением ответов Gemini.'],
            ['user_agent' => 'Google-Extended', 'role' => 'training_grounding', 'kind' => 'control_token', 'reference' => $google.'#google-extended',
                'note' => 'Управляющий токен robots.txt, не отдельный HTTP-бот. Регулирует обучение Gemini и grounding в поддерживаемых продуктах; не управляет включением в Google Search.'],
            ['user_agent' => 'YandexBot', 'role' => 'search', 'kind' => 'crawler', 'reference' => $yandex,
                'note' => 'Основной индексирующий робот Яндекса. Учитывается группа YandexBot, затем Yandex, затем *. Не является отдельной проверкой ответов Алисы или Нейро.'],
            ['user_agent' => 'Bytespider', 'role' => 'other', 'kind' => 'unknown', 'reference' => null,
                'note' => 'Назначение не подтверждено доступным официальным источником. Показано только правило сайта для этого имени, без утверждения об обучении или соблюдении robots.txt.'],
        ];
    }

    private function agentRule(string $url, ?array $robots, string $agent): ?bool
    {
        if (! $robots || ! in_array($robots['status'], [200, 404, 410], true)) {
            return null;
        }
        if ($agent === 'YandexBot' && $robots['status'] === 200) {
            // https://yandex.com/support/webmaster/en/robot-workings/user-agent
            $body = preg_replace('/#[^\r\n]*/', '', $robots['body']);
            if (! preg_match('/^\s*user-agent\s*:\s*yandexbot(?:\/1\.0)?\s*$/mi', $body)
                && preg_match('/^\s*user-agent\s*:\s*yandex\s*$/mi', $body)) {
                $agent = 'Yandex';
            }
        }

        return RobotsPolicy::allowed($url, $robots, $agent);
    }

    private function origin(string $url): string
    {
        return strtolower(parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST)).(($port = parse_url($url, PHP_URL_PORT)) ? ':'.$port : '');
    }

    private function fetchBounded(string $url): array
    {
        $this->checkDeadline();
        if ($this->requests >= 60) {
            throw new \RuntimeException('Per-site request budget reached');
        }$this->requests++;

        return $this->crawler->fetch($url, min(8, max(0.1, $this->deadline - $this->clock())));
    }

    protected function clock(): float
    {
        return microtime(true);
    }

    private function checkDeadline(): void
    {
        if ($this->clock() >= $this->deadline) {
            throw new \RuntimeException('Site audit timed out.');
        }
    }

    private function robots(string $url): ?array
    {
        $origin = $this->origin($url);
        if (array_key_exists($origin, $this->robotsCache)) {
            return $this->robotsCache[$origin];
        }
        $target = $origin.'/robots.txt';
        $seen = [];
        try {
            for ($i = 0; $i < 5; $i++) {
                if (isset($seen[$target])) {
                    break;
                }$seen[$target] = true;
                $response = $this->fetchBounded($target);
                if (! in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                    return $this->robotsCache[$origin] = $response;
                }
                $target = $this->resolve($target, $response['location'] ?? '');
            }
        } catch (\Throwable) {
        }

        return $this->robotsCache[$origin] = null;
    }

    private function fetchAllowed(string $url): array
    {
        $seen = [];
        for ($i = 0; $i < 5; $i++) {
            if (isset($seen[$url]) || ! $this->allowed($url, $this->robots($url))) {
                throw new \RuntimeException('Crawl policy unavailable or disallowed');
            }$seen[$url] = true;
            $response = $this->fetchBounded($url);
            // The crawler must not follow redirects before the destination policy is checked.
            if ($this->origin($response['url']) !== $this->origin($url)) {
                throw new \RuntimeException('Crawler redirected outside checked origin');
            }
            if (! in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                return $response;
            }
            $url = $this->resolve($url, $response['location'] ?? '');
        }throw new \RuntimeException('Too many redirects');
    }

    private function resolve(string $base, string $reference): string
    {
        if ($reference === '') {
            throw new \RuntimeException('Empty redirect target');
        }
        SiteSafety::noQuery($base);
        SiteSafety::noQuery($reference);
        $uri = UriResolver::resolve(new Uri($base), new Uri($reference))->withFragment('');
        SiteSafety::noQuery((string) $uri);
        if (! in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getUserInfo() !== '') {
            throw new \RuntimeException('Unsupported URL');
        }

        return (string) $uri;
    }

    private function dom(string $html): \DOMDocument
    {
        $dom = new \DOMDocument;
        $old = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.($html ?: '<html></html>'), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        HiddenContent::remove($dom);

        return $dom;
    }

    /** Explicit examples remain in crawl text, but cannot prove the site's commercial terms. */
    private function commercialDom(\DOMDocument $source): \DOMDocument
    {
        $dom = clone $source;
        $nodes = iterator_to_array((new \DOMXPath($dom))->query('//*'));
        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement || ! $node->parentNode) {
                continue;
            }
            $marked = preg_match('/(?:^|[\s_-])(?:demo|example|sample|mock)(?:$|[\s_-])/i',
                $node->getAttribute('id').' '.$node->getAttribute('class'))
                || $node->hasAttribute('data-demo') || $node->hasAttribute('data-example')
                || preg_match('/^(?:пример\b|образец\b|демонстрацион|example\b|sample\b|demo\b)/iu', trim($node->getAttribute('aria-label')));
            $tag = strtolower($node->tagName);
            $heading = in_array($tag, ['h2', 'h3', 'h4'], true);
            if ($marked && ! $heading && $tag !== 'caption') {
                $node->parentNode->removeChild($node);

                continue;
            }
            if (($heading || $tag === 'caption') && ($marked
                || preg_match('/^(?:пример\b|образец\b|демонстраци|демо\b|example\b|sample\b|demonstration\b)/iu', trim($node->textContent)))) {
                if ($heading) {
                    $level = (int) substr($tag, 1);
                    $boundary = implode('|', array_map(fn (int $rank): string => './/h'.$rank, range(1, $level)));
                    for ($current = $node; $current !== null; $current = $next) {
                        if ($current !== $node && $current instanceof \DOMElement
                            && (preg_match('/^h[1-'.$level.']$/', strtolower($current->tagName))
                                || in_array(strtolower($current->tagName), ['section', 'article', 'nav', 'footer', 'header', 'main', 'aside'], true)
                                || (new \DOMXPath($dom))->query($boundary, $current)->length > 0)) {
                            break;
                        }
                        $next = $current->nextSibling;
                        $current->parentNode?->removeChild($current);
                    }

                    continue;
                }
                $container = $node->parentNode;
                while ($container instanceof \DOMElement && strtolower($container->tagName) !== 'table') {
                    $container = $container->parentNode;
                }
                if ($container instanceof \DOMElement && $container->parentNode) {
                    $container->parentNode->removeChild($container);
                }
            }
        }

        return $dom;
    }

    /** Keep conditions in their own text block; a case outcome or checklist is not the site's offer. */
    private function fulfilmentEvidence(\DOMDocument $dom): ?string
    {
        $blocks = [];
        $text = '';
        $caseHeading = '/\b(?:кейс\p{L}*|чек[\s-]?лист\p{L}*|истории клиентов|case stud(?:y|ies)|checklist)\b/iu';
        $flush = function () use (&$blocks, &$text): void {
            $value = trim(preg_replace('/\s+/u', ' ', $text));
            if ($value !== '') {
                $blocks[] = $value;
            }
            $text = '';
        };
        $walk = function (\DOMNode $node) use (&$walk, &$text, $flush, $caseHeading): void {
            if ($node instanceof \DOMText) {
                $text .= $node->textContent;

                return;
            }
            $tag = $node instanceof \DOMElement ? strtolower($node->tagName) : '';
            $boundary = in_array($tag, ['html', 'body', 'main', 'section', 'article', 'aside', 'header', 'footer',
                'div', 'p', 'li', 'ul', 'ol', 'table', 'tr', 'td', 'th', 'dl', 'dt', 'dd', 'h1', 'h2', 'h3', 'h4', 'br'], true);
            if ($boundary) {
                $flush();
            }
            if ($node instanceof \DOMElement) {
                $label = $node->getAttribute('id').' '.$node->getAttribute('class');
                if (preg_match('/(?:^|[\s_-])(?:cases?|case-stud(?:y|ies)|checklist|testimonials?)(?:$|[\s_-])/i', $label)
                    || preg_match($caseHeading, $node->getAttribute('aria-label'))) {
                    $flush();

                    return;
                }
            }
            $excludedLevel = null;
            foreach ($node->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $childTag = strtolower($child->tagName);
                    $headingLevel = preg_match('/^h([1-6])$/', $childTag, $heading) ? (int) $heading[1] : null;
                    if ($excludedLevel !== null && $headingLevel !== null && $headingLevel <= $excludedLevel) {
                        $excludedLevel = null;
                    }
                    if ($headingLevel !== null && preg_match($caseHeading, $child->textContent)) {
                        $excludedLevel = $excludedLevel === null ? $headingLevel : min($excludedLevel, $headingLevel);
                        $flush();
                    }
                }
                if ($excludedLevel !== null) {
                    continue;
                }
                $walk($child);
            }
            if ($boundary) {
                $flush();
            }
        };
        $walk($dom);
        $flush();

        $duration = '\d+(?:\s*[–—-]\s*\d+)?\s*(?:рабочи[хйе]\s+)?(?:минут\p{L}*|час\p{L}*|дн(?:я|ей)|день|недел\p{L}*)(?!\p{L})';
        $condition = '(?:(?:(?:за|через|в течение|от|до)\s+)?'.$duration
            .'|бесплатн\p{L}*|курьером|онлайн|удал[её]нно|после оплаты|по всей\b|по адресу|со склада|из пункта)';
        $pattern = '~(?<!\p{L})(?:доставка|доставляем|доставим|самовывоз|подключение|подключаем|подключим'
            .'|предоставление доступа|доступ (?:предоставляется|открывается)|предоставляем доступ)(?!\p{L})'
            .'\s*(?:[:—-]\s*)?(?:(?:товар(?:а|ов)?|заказ(?:а|ов)?|продукции|сервис(?:а)?|услуг[иу]|систем[ыу]|аккаунт(?:а)?)\s+)?'
            .'(?:(?:занимает|осуществляется|выполняется)\s+)?'.$condition.'~iu';
        foreach ($blocks as $block) {
            if (preg_match('/\b(?:кейс\p{L}*|данные вендора|чек[\s-]?лист\p{L}*)\b/iu', $block)
                || preg_match('/(?:проверьте|убедитесь|должн\p{L}*\s+(?:быть\s+)?(?:описан|указан|обновл)|существу\p{L}*\s+в текстовом)/iu', $block)) {
                continue;
            }
            if (preg_match($pattern, $block, $match, PREG_OFFSET_CAPTURE)) {
                return $this->excerpt($block, $match[0][1], strlen($match[0][0]));
            }
        }

        return null;
    }

    private function offerCard(\DOMElement $anchor): bool
    {
        $xpath = new \DOMXPath($anchor->ownerDocument);
        if ($xpath->query('.//h2|.//h3|.//h4', $anchor)->length === 0
            || $xpath->query('.//p', $anchor)->length === 0) {
            return false;
        }
        for ($parent = $anchor->parentNode; $parent instanceof \DOMElement; $parent = $parent->parentNode) {
            if (preg_match('/(?:^|[\s_-])(?:catalog|products|services|solutions|modules|offerings)(?:$|[\s_-])/i',
                $parent->getAttribute('id').' '.$parent->getAttribute('class'))) {
                return true;
            }
        }

        return false;
    }

    private function isSitemap(?array $page): bool
    {
        if (! $page || $page['status'] !== 200 || trim($page['body']) === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $page['body'])) {
            return false;
        }
        $old = libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        try {
            $loaded = $doc->loadXML($page['body'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }

        return $loaded && in_array($doc->documentElement?->localName, ['urlset', 'sitemapindex'], true);
    }

    private function links(string $html, string $base): array
    {
        $urls = [];
        $host = parse_url($base, PHP_URL_HOST);
        $scheme = parse_url($base, PHP_URL_SCHEME);
        foreach ($this->dom($html)->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            if (! $href || preg_match('/^(#|mailto:|tel:|javascript:|data:)/i', $href)) {
                continue;
            }
            try {
                $href = $this->resolve($base, $href);
            } catch (\Throwable) {
                continue;
            }
            if (parse_url($href, PHP_URL_HOST) !== $host || $href === $base || preg_match('/\.(pdf|zip|jpg|png|webp|svg|mp4)$/i', $href)) {
                continue;
            }
            $urls[$href] = preg_match('/product|catalog|service|contact|about|delivery|price|produk|kontak/i', $href) ? 0 : 1;
        }
        asort($urls);

        return array_keys($urls);
    }

    private function allowed(string $url, ?array $robots): bool
    {
        return RobotsPolicy::allowed($url, $robots);
    }

    private function criteria(array $root, array $pages, array $schemas, array $links, ?array $robots, ?array $sitemap): array
    {
        $titles = [1 => 'Доступность содержимого', 2 => 'Обнаружение страниц и каталога', 3 => 'Правила доступа роботов', 4 => 'Извлечение текста и структурированных данных', 5 => 'Продукт и его назначение', 6 => 'Параметры и единицы измерения', 7 => 'Категории и варианты', 8 => 'Идентификаторы и адреса продуктов', 9 => 'Цена или порядок расчёта', 10 => 'Наличие и минимальный заказ', 11 => 'Получение товара или доступ к услуге', 12 => 'Контакт и путь покупки', 13 => 'Сведения о компании', 14 => 'Подтверждение заявлений', 15 => 'Согласованность фактов', 16 => 'Актуальность данных', 17 => 'Понимание продукта', 18 => 'Понимание параметров', 19 => 'Понимание ограничений', 20 => 'Корректный следующий шаг'];
        $out = [];
        foreach ($titles as $id => $title) {
            $out[$id] = ['id' => $id, 'group' => ['Техническая доступность', 'Структура предложения', 'Коммерческие условия', 'Доверие', 'Понимание ИИ'][intdiv($id - 1, 4)], 'title' => $title, 'score' => null, 'weight' => 5, 'status' => 'unknown', 'pending' => $id >= 17, 'in_scope' => $id <= 16, 'evidence' => [], 'explanation' => $id >= 17 ? 'Отдельная проверка понимания моделями не входит в аудит сайта и не включается в число его 16 проверок. Для неё нужны утверждённые задания и независимые эталонные факты.' : 'В загруженных страницах не найдено достаточного подтверждения. Это не доказывает отсутствие сведений на всём сайте.'];
        }
        $set = function (int $id, float $score, string $explanation, array $page, string $excerpt) use (&$out) {
            if ($out[$id]['score'] !== null && $out[$id]['score'] >= $score) {
                return;
            }
            $out[$id] = array_replace($out[$id], ['score' => $score, 'status' => $score === 1.0 ? 'pass' : ($score === 0.0 ? 'fail' : 'partial'), 'explanation' => $explanation, 'evidence' => [['id' => 'c'.$id.'-'.substr(hash('sha256', $page['url']), 0, 12), 'url' => $page['url'], 'excerpt' => mb_substr($excerpt, 0, 600), 'observed_at' => $page['observed_at'] ?? $page['fetched_at'] ?? now()->toIso8601String()]]]);
        };
        $first = $pages[0] ?? ['url' => $root['url'], 'text' => '', 'observed_at' => $root['fetched_at']];
        $length = mb_strlen($first['text']);
        if ($root['status']) {
            $set(1, ($root['status'] >= 200 && $root['status'] < 300 && $length >= 120) ? 1.0 : 0.0, 'HTTP '.$root['status'].'; извлечено '.$length.' символов.', $first, mb_substr($first['text'], 0, 250));
        }
        $validSitemap = $this->isSitemap($sitemap);
        if ($links || $validSitemap) {
            $set(2, ($links && $validSitemap) ? 1.0 : .5, 'Найдены внутренние ссылки: '.count($links).'. Корректность карты сайта оценивается отдельно от полноты каталога.', $links ? $first : $sitemap, $links ? implode(' | ', array_slice($links, 0, 4)) : mb_substr($sitemap['body'], 0, 400));
            if ($links && $validSitemap) {
                $out[2]['evidence'][] = ['id' => 'c2-'.substr(hash('sha256', $sitemap['url']), 0, 12), 'url' => $sitemap['url'], 'excerpt' => mb_substr($sitemap['body'], 0, 400), 'observed_at' => $sitemap['fetched_at']];
            }
        }
        if ($robots && in_array($robots['status'], [200, 404, 410])) {
            $metaBlocked = false;
            foreach ($this->dom($root['body'])->getElementsByTagName('meta') as $meta) {
                if (in_array(strtolower($meta->getAttribute('name')), ['robots', 'eyon-audit'], true) && preg_match('/\b(noindex|none)\b/i', $meta->getAttribute('content'))) {
                    $metaBlocked = true;
                }
            }
            $blocked = ! $this->allowed($root['url'], $robots) || preg_match('/\b(noindex|none)\b/i', $root['x_robots_tag'] ?? '') || $metaBlocked;
            if ($blocked || $root['status']) {
                $set(3, $blocked ? 0.0 : 1.0, $blocked ? 'Найдены ограничения индексации.' : 'В проверенных robots.txt, meta robots и X-Robots-Tag явного запрета не найдено.', $robots, 'HTTP '.$robots['status'].' '.mb_substr($robots['body'], 0, 450));
            }
        }
        if ($length >= 120 && $root['status'] >= 200 && $root['status'] < 300) {
            $set(4, $schemas ? 1.0 : .5, $schemas ? 'Извлекаются текст и корректные блоки JSON-LD.' : 'Текст извлекается; корректный JSON-LD в выборке не найден.', $schemas[0]['page'] ?? $first, $schemas ? json_encode($schemas[0]['data'], JSON_UNESCAPED_UNICODE) : mb_substr($first['text'], 0, 400));
        }
        $nodes = [];
        $walk = function ($v, $page) use (&$walk, &$nodes) {
            if (! is_array($v)) {
                return;
            }if (isset($v['@type'])) {
                $nodes[] = ['node' => $v, 'page' => $page];
            }foreach ($v as $item) {
                if (is_array($item)) {
                    $walk($item, $page);
                }
            }
        };
        foreach ($schemas as $schema) {
            $walk($schema['data'], $schema['page']);
        }
        $productFacts = [];
        $offerPages = [];
        foreach ($nodes as $entry) {
            $node = $entry['node'];
            $sourcePage = $entry['page'];
            $types = (array) ($node['@type'] ?? []);
            $excerpt = json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (array_intersect($types, ['Product', 'Service', 'SoftwareApplication'])) {
                if (! empty($node['name']) && is_string($node['name']) && ! empty($node['description'])) {
                    $offerPages[$sourcePage['url']][] = $node['name'];
                }
                if (! empty($node['name']) && ! empty($node['description'])) {
                    $set(5, .5, 'Структурированное описание найдено; смысловая полнота ещё не подтверждена.', $sourcePage, $excerpt);
                }
                if (! empty($node['additionalProperty'])) {
                    $set(6, .5, 'Найдены структурированные параметры; единицы и полнота требуют проверки.', $sourcePage, $excerpt);
                }
                if (! empty($node['category']) || ! empty($node['hasVariant'])) {
                    $set(7, .5, 'Указаны категории или варианты.', $sourcePage, $excerpt);
                }
                if ((! empty($node['sku']) || ! empty($node['mpn'])) && ! empty($node['url'])) {
                    $set(8, 1.0, 'В разметке указаны идентификатор и URL товара.', $sourcePage, $excerpt);
                }
                $offers = $node['offers'] ?? [];
                if (isset($offers[0])) {
                    $offers = $offers[0];
                }
                if (is_array($offers) && isset($offers['price'],$offers['priceCurrency'])) {
                    $set(9, .5, 'В разметке есть цена и валюта; актуальность ещё не подтверждена.', $sourcePage, json_encode($offers, JSON_UNESCAPED_UNICODE));
                    $identity = $node['sku'] ?? $node['mpn'] ?? $node['url'] ?? null;
                    if (is_string($identity) && is_numeric($offers['price']) && is_string($offers['priceCurrency'])) {
                        $productFacts[$identity][] = ['value' => (string) (float) $offers['price'].' '.strtoupper($offers['priceCurrency']), 'page' => $sourcePage];
                    }
                }
                if (is_array($offers) && ! empty($offers['availability'])) {
                    $set(10, .5, 'Указано наличие; условия подтверждения и минимальный заказ требуют проверки.', $sourcePage, json_encode($offers, JSON_UNESCAPED_UNICODE));
                }
                if (is_array($offers) && ! empty($offers['shippingDetails'])) {
                    $set(11, .5, 'Найдены условия доставки; полнота требует проверки.', $sourcePage, json_encode($offers, JSON_UNESCAPED_UNICODE));
                }
            }
            if (array_intersect($types, ['Organization', 'LocalBusiness']) && ! empty($node['name']) && (! empty($node['taxID']) || ! empty($node['legalName']))) {
                $set(13, .5, 'Реквизиты есть в разметке; независимая проверка не выполнена.', $sourcePage, $excerpt);
            }
            if (isset($node['dateModified']) && is_string($node['dateModified'])
                && preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:T(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d{1,6})?)?(?:Z|[+-](?:0\d|1[0-3]):[0-5]\d|[+-]14:00)?)?$/D', $node['dateModified'], $dateParts)
                && checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1])) {
                try {
                    $date = CarbonImmutable::parse($node['dateModified']);
                    if ($date->year >= 2000 && $date->lessThanOrEqualTo(now())) {
                        $set(16, .5, 'Источник указывает дату изменения '.$date->format('d.m.Y').'. Это заявленная дата, а не независимое подтверждение актуальности всех условий.', $sourcePage, '"dateModified": '.$node['dateModified']);
                    }
                } catch (\Throwable) {
                }
            }
        }
        foreach ($productFacts as $identity => $facts) {
            $distinctPages = collect($facts)->pluck('page.url')->unique()->count();
            if ($distinctPages >= 2 && count(array_unique(array_column($facts, 'value'))) === 1) {
                $set(15, .5, 'Цена одного идентификатора совпала на '.$distinctPages.' загруженных страницах. Остальные факты не проверены на согласованность.', $facts[0]['page'], $identity.': '.$facts[0]['value']);
                foreach (array_slice($facts, 1, 3) as $fact) {
                    $out[15]['evidence'][] = ['id' => 'c15-'.substr(hash('sha256', $fact['page']['url']), 0, 12), 'url' => $fact['page']['url'],
                        'excerpt' => $identity.': '.$fact['value'], 'observed_at' => $fact['page']['fetched_at']];
                }
            }
        }
        $signals = [
            6 => ['~\d+(?:[.,]\d+)?\s*(?:мм|см|кг|мг|гб|мб|м²|м2|кВт|Мбит/с|Гбит/с|минут|часов|дней)(?!\p{L})~iu', 'В тексте найдены параметры с единицами. Полнота характеристик требует проверки.'],
            9 => ['~(?:\d[\d\s.,]*\s*(?:₽|руб(?:лей|ля|ль|\.)?|USD|RUB|EUR|тенге)|(?:стоимость|цен[ауы]|расч[её]т)[^.!?]{0,65}(?:индивидуальн|по запросу|после|зависит|рассчитыва|рассчита|от \d))~iu', 'Найдена цена или объяснение её расчёта в тексте. Актуальность и полный состав стоимости требуют проверки.'],
            10 => ['~(?:в наличии|под заказ|минимальн\p{L}*\s+(?:заказ|объ[её]м|сумм)[^.!?]{0,80}|(?:подключение|запуск|изготовление)[^.!?]{0,35}(?:от|до|за|в течение)\s+\d[^.!?]{0,35})~iu', 'Найдено описание наличия, минимального заказа или срока начала работы. Приведено свидетельство, а не гарантия исполнения.'],
            12 => ['~[\w.\-+]+@[\w.\-]+\.[a-z]{2,}|\+7[\s()\d-]{9,}~iu', 'Найден контакт в тексте. Прохождение заявки и ответа компании отдельно не проверялось.'],
            13 => ['~(?:ИНН\s*[:№]?\s*\d{10}(?:\d{2})?(?!\d)|ОГРН(?:ИП)?\s*[:№]?\s*\d{13,15}(?!\d)|(?:ООО|АО|ИП)\s+[«"][^»"]{2,100}[»"])~u', 'В тексте найдены реквизиты или юридическое наименование. Независимая проверка компании не проводилась.'],
        ];
        $loadedPages = collect($pages)->where('status', '>=', 200)->where('status', '<', 300)->keyBy('url')->all();
        foreach ($pages as $page) {
            if ($page['status'] < 200 || $page['status'] >= 300) {
                continue;
            }
            $commercialText = $page['commercial_text'] ?? '';
            foreach ($signals as $id => [$pattern, $explanation]) {
                if (preg_match($pattern, $commercialText, $match, PREG_OFFSET_CAPTURE)) {
                    $set($id, .5, $explanation, $page, $this->excerpt($commercialText, $match[0][1], strlen($match[0][0])));
                }
            }
            if (! empty($page['fulfilment_evidence'])) {
                $set(11, .5, 'В тексте найдены условия получения товара или доступа к услуге. Полнота условий требует проверки.', $page, $page['fulfilment_evidence']);
            }
            if (mb_strlen($commercialText) >= 120 && ($page['headings'] ?? []) !== []) {
                $heading = $page['headings'][0];
                if (mb_strlen($heading) >= 12 && ! preg_match('/^(?:о нас|контакты|главная|политика|отзывы|новости)\b/iu', $heading)) {
                    $set(5, .5, 'Найдены заголовок предложения и текст страницы. Соответствие продукта потребности требует смысловой проверки.', $page, $heading.' — '.mb_substr($commercialText, 0, 350));
                }
            }
            $catalogLinks = [];
            foreach ($page['references'] ?? [] as $reference) {
                $host = strtolower((string) parse_url($reference['url'], PHP_URL_HOST));
                $path = (string) parse_url($reference['url'], PHP_URL_PATH);
                $messenger = (in_array($host, ['t.me', 'telegram.me'], true) && preg_match('~^/(?!share(?:/|$)|iv(?:/|$)|proxy(?:/|$))[a-zA-Z0-9_]{5,}/?$~D', $path))
                    || ($host === 'wa.me' && preg_match('~^/\d{7,15}/?$~D', $path))
                    || ($host === 'api.whatsapp.com' && $path === '/send' && preg_match('/(?:^|&)phone=\d{7,15}(?:&|$)/', (string) parse_url($reference['url'], PHP_URL_QUERY)));
                if ($messenger) {
                    $set(12, .5, 'Найдена ссылка для связи в мессенджере. Доставка сообщения и ответ компании не проверялись.', $page, $reference['label'].' — '.$reference['url']);
                }
                if ($host !== strtolower((string) parse_url($root['url'], PHP_URL_HOST))) {
                    continue;
                }
                if ($this->offerReference($reference, $loadedPages, $offerPages)) {
                    $catalogLinks[$reference['url']] = $reference['label'].' — '.$reference['url'];
                }
                if (preg_match('/сертификат|лицензи[яи]|протокол испытан|certificate|license/iu', $reference['label'])
                    && preg_match('~\.(?:pdf|jpe?g|png)(?:$|\?)|/(?:certificates?|licenses?)/~i', $reference['url'])) {
                    $set(14, .5, 'Найдена ссылка на подтверждающий документ. Содержание и подлинность документа не проверены.', $page, $reference['label'].' — '.$reference['url']);
                }
            }
            if (count($catalogLinks) >= 2) {
                $set(7, .5, 'На странице найдены разные ссылки каталога или услуг. Полнота вариантов требует проверки.', $page, implode(' | ', array_slice($catalogLinks, 0, 3)));
            }
            if ($catalogLinks) {
                $set(8, .5, 'Найдены отдельные адреса предложений. Артикулы и устойчивость идентификаторов требуют проверки.', $page, implode(' | ', array_slice($catalogLinks, 0, 3)));
            }
        }

        return array_values($out);
    }

    private function offerReference(array $reference, array $loadedPages, array $offerPages): bool
    {
        $path = (string) parse_url($reference['url'], PHP_URL_PATH);
        $label = $this->normalizedLabel($reference['label']);
        $loaded = $loadedPages[$reference['url']] ?? null;
        $heading = $this->normalizedLabel($loaded['primary_heading'] ?? '');
        $informational = '/^(?:новости|блог|стать[яи]|политика|конфиденциальност|контакты|о компании|о нас|отзывы|news|blog|privacy|contact|about)\b/iu';
        if (preg_match('~/(?:news|blog|articles?|privacy|policy|terms|contacts?|about|support|docs|cases?|reviews?)(?:[./_-]|$)~i', $path)
            || preg_match('~^/(?:index\.html?)?$~i', $path)
            || preg_match($informational, $label) || preg_match($informational, $heading)) {
            return false;
        }
        if (mb_strlen($label) >= 3 && preg_match('~/(?:catalog|products?|services?|solutions?|uslugi|tovary)(?:/|$)~i', $path)) {
            return true;
        }
        if (! $loaded || mb_strlen($label) < 5) {
            return false;
        }
        $schemaNames = array_map($this->normalizedLabel(...), $offerPages[$reference['url']] ?? []);
        if (mb_strlen($loaded['commercial_text'] ?? '') < 120 && $schemaNames === []) {
            return false;
        }
        if (($reference['offer_card'] ?? false) && mb_strlen($heading) >= 12) {
            return true;
        }
        foreach ([$heading, ...$schemaNames] as $name) {
            if (mb_strlen($name) >= 5 && ($this->labelContains($name, $label) || $this->labelContains($label, $name))) {
                return true;
            }
        }

        return false;
    }

    private function normalizedLabel(string $text): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
    }

    private function labelContains(string $text, string $name): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', $text) === 1;
    }

    private function excerpt(string $text, int $offset, int $length): string
    {
        $start = max(0, $offset - 120);

        return trim(mb_strcut($text, $start, $length + 300, 'UTF-8'));
    }
}
