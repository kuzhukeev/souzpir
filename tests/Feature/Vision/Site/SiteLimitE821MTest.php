<?php

namespace Tests\Feature\Vision\Site;

use App\Vision\SiteAudit\CrawlerClient;
use App\Vision\SiteAudit\SiteInspector;
use Tests\TestCase;

final class SiteLimitE821MTest extends TestCase
{
    public function test_fake_audit_processes_at_most_fifty_pages(): void
    {
        $links = '';
        for ($i = 1; $i <= 60; $i++) {
            $links .= '<a href="/page'.$i.'">Страница</a>';
        }
        $crawler = \Mockery::mock(CrawlerClient::class);
        $crawler->shouldReceive('fetch')->andReturnUsing(function (string $url) use ($links): array {
            $body = match ($url) {
                'https://example.com/robots.txt' => "User-agent: *\nAllow: /",
                'https://example.com/' => '<html>'.$links.'</html>',
                default => '<html><title>Проверенная страница</title></html>',
            };

            return ['url' => $url, 'body' => $body, 'status' => 200, 'fetched_at' => now()->toIso8601String()];
        });
        $last = [];
        $result = (new SiteInspector($crawler))->inspect('https://example.com/', 100, function (int $done, int $total) use (&$last): void {
            $last = [$done, $total];
        });
        self::assertSame(50, $result['crawled_pages']);
        self::assertSame([50, 50], $last);
        self::assertStringContainsString('до 50 страниц', $result['scope']);
    }
}
