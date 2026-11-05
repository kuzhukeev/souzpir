<?php

namespace Tests\Feature\Vision\Site;

use App\Vision\Site\Jobs\AuditSiteJob;
use App\Vision\SiteAudit\CrawlerClient;
use App\Vision\SiteAudit\SiteInspector;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SiteTimingClockE821O extends SiteInspector
{
    public float $time = 0;

    protected function clock(): float
    {
        return $this->time;
    }
}

final class SiteTimingE821OTest extends TestCase
{
    public function test_fake_inspection_and_queue_settings_do_not_support_a_two_minute_estimate(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $crawler = \Mockery::mock(CrawlerClient::class);
        $inspector = new SiteTimingClockE821O($crawler);
        $requests = 0;
        $crawler->shouldReceive('fetch')->andReturnUsing(function (string $url, float $timeout) use ($inspector, &$requests): array {
            $requests++;
            $inspector->time += 1;
            $body = str_ends_with($url, '/robots.txt') ? "User-agent: *\nAllow: /" : '<html><a href="/a">a</a><a href="/b">b</a></html>';

            return ['url' => $url, 'body' => $body, 'status' => 200, 'fetched_at' => now()->toIso8601String()];
        });
        $inspector->inspect('https://example.test/', 5);
        self::assertGreaterThan(0, $requests);
        self::assertSame((float) $requests, $inspector->time);
        self::assertLessThan(60, $inspector->time);
        $job = new AuditSiteJob('org', 'brand', 'actor', 'membership', 'https://example.test/', 'session', 1);
        self::assertLessThan(120, $job->timeout);
        foreach (['database', 'redis', 'beanstalkd'] as $connection) {
            self::assertGreaterThan($job->timeout, config("queue.connections.$connection.retry_after"));
        }
        Http::assertNothingSent();
    }
}
