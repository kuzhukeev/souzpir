<?php

namespace Tests\Feature\Vision\Site;

use App\Vision\SiteAudit\CrawlerClient;
use App\Vision\SiteAudit\SiteInspector;
use Tests\TestCase;

final class SiteSignalsE821ETest extends TestCase
{
    public function test_markup_indicators_and_processed_page_counts_use_observed_pages(): void
    {
        $root = 'https://example.com/';
        $page = fn (string $url, string $body, int $status = 200): array => ['url' => $url, 'body' => $body, 'status' => $status, 'fetched_at' => '2026-11-04T10:00:00Z'];
        $pages = [
            'https://example.com/robots.txt' => $page('https://example.com/robots.txt', "User-agent: *\nAllow: /"),
            $root => $page($root, '<html><a href="/catalog">Каталог</a><script src="https://www.google.com/recaptcha/api.js"></script></html>'),
            'https://example.com/catalog' => $page('https://example.com/catalog', '<html><div class="cf-turnstile"></div><script type="application/ld+json">{"@graph":[{"@type":"Product","name":"Товар"}]}</script></html>'),
            'https://example.com/sitemap.xml' => $page('https://example.com/sitemap.xml', '', 404),
            'https://example.com/llms.txt' => $page('https://example.com/llms.txt', '', 404),
        ];
        $crawler = \Mockery::mock(CrawlerClient::class);
        $crawler->shouldReceive('fetch')->andReturnUsing(fn ($url) => $pages[$url] ?? throw new \RuntimeException('Unexpected URL'));
        $progress = [];
        $result = (new SiteInspector($crawler))->inspect($root, 5, function (int $done, int $total) use (&$progress): void {
            $progress[] = [$done, $total];
        });
        self::assertSame([[1, 2], [2, 2]], $progress);
        self::assertSame(['reCAPTCHA', 'Cloudflare Turnstile'], $result['ai_access']['captcha']['indicators']);
        self::assertSame(2, $result['ai_access']['captcha']['pages_observed']);
        self::assertSame(2, $result['crawled_pages']);
        self::assertSame(['Product'], $result['pages'][1]['structured_types']);
        self::assertSame([['label' => 'Название', 'value' => 'Товар']], $result['pages'][1]['structured_facts']);
    }

    public function test_missing_or_plain_text_mentions_do_not_claim_detection(): void
    {
        $crawler = \Mockery::mock(CrawlerClient::class);
        $crawler->shouldReceive('fetch')->andReturnUsing(fn ($url) => ['url' => $url, 'body' => $url === 'https://example.com/' ? '<p>Статья о reCAPTCHA</p>' : '', 'status' => $url === 'https://example.com/' ? 200 : 404, 'fetched_at' => now()->toIso8601String()]);
        $result = (new SiteInspector($crawler))->inspect('https://example.com/', 1);
        self::assertSame([], $result['ai_access']['captcha']['indicators']);
        self::assertSame(1, $result['ai_access']['captcha']['pages_observed']);
    }
}
