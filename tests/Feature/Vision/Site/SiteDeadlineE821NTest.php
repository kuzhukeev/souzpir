<?php

namespace Tests\Feature\Vision\Site;

use App\Vision\Site\Jobs\AuditSiteJob;
use App\Vision\SiteAudit\CrawlerClient;
use App\Vision\SiteAudit\SiteInspector;
use RuntimeException;
use Tests\TestCase;

final class TimedSiteInspectorE821N extends SiteInspector
{
    public float $time = 0;

    protected function clock(): float
    {
        return $this->time;
    }
}

final class SiteDeadlineE821NTest extends TestCase
{
    public function test_slow_fake_site_stops_at_deadline_without_live_requests(): void
    {
        $crawler = \Mockery::mock(CrawlerClient::class);
        $inspector = new TimedSiteInspectorE821N($crawler);
        $timeouts = [];
        $crawler->shouldReceive('fetch')->andReturnUsing(function (string $url, float $timeout) use ($inspector, &$timeouts): array {
            $timeouts[] = $timeout;
            $inspector->time += 8;
            $links = implode('', array_map(fn ($i) => '<a href="/page'.$i.'">Page</a>', range(1, 30)));

            return ['url' => $url, 'body' => str_ends_with($url, '/robots.txt') ? "User-agent: *\nAllow: /" : '<html>'.$links.'</html>', 'status' => 200, 'fetched_at' => now()->toIso8601String()];
        });
        try {
            $inspector->inspect('https://example.test/', 50);
            self::fail('Timed out inspection must not return a complete result.');
        } catch (RuntimeException) {
            self::assertGreaterThanOrEqual(60, $inspector->time);
            self::assertLessThanOrEqual(8, max($timeouts));
            self::assertLessThan(20, count($timeouts));
        }
        $job = new AuditSiteJob('org', 'brand', 'actor', 'membership', 'https://example.test/', 'session', 1);
        self::assertSame(75, $job->timeout);
        self::assertSame(1, $job->tries);
    }
}
