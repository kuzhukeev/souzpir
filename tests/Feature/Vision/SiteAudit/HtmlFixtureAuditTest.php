<?php

namespace Tests\Feature\Vision\SiteAudit;

use App\Vision\SiteAudit\PublicAddressResolver;
use App\Vision\SiteAudit\SiteInspector;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HtmlFixtureAuditTest extends TestCase
{
    private const ORIGIN = 'https://fixture.example.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 31)->startOfDay());
        config(['features.E8-01B.defaults.fetch_enabled' => true]);
        Http::preventStrayRequests();
        $this->app->instance(PublicAddressResolver::class, new class implements PublicAddressResolver
        {
            public function addresses(string $host): array
            {
                if ($host !== 'fixture.example.com') {
                    throw new \RuntimeException('Unexpected fixture DNS host.');
                }

                return ['93.184.216.34'];
            }
        });
    }

    public function test_static_html_is_fetched_through_real_crawler_and_keeps_evidence_sources(): void
    {
        $this->fakeSite();

        $result = app(SiteInspector::class)->inspect(self::ORIGIN, 2);
        $criteria = collect($result['criteria'])->keyBy('id');

        $this->assertCount(20, $criteria);
        $this->assertSame(range(1, 20), $criteria->keys()->all());
        $this->assertSame(16, $criteria->where('in_scope', true)->count());
        $this->assertSame(4, $criteria->where('pending', true)->count());
        $this->assertSame([5], $criteria->pluck('weight')->unique()->values()->all());
        foreach ([1, 2, 3, 4, 8] as $id) {
            $this->assertSame(1.0, $criteria[$id]['score'], 'criterion '.$id);
        }
        foreach ([5, 6, 7, 9, 10, 11, 12, 13, 14, 16] as $id) {
            $this->assertSame(.5, $criteria[$id]['score'], 'criterion '.$id);
            $this->assertNotEmpty($criteria[$id]['evidence']);
        }
        $this->assertSame(self::ORIGIN.'/catalog/profile', $criteria[8]['evidence'][0]['url']);
        $this->assertStringContainsString('FIXTURE-001', $criteria[8]['evidence'][0]['excerpt']);
        $this->assertSame(self::ORIGIN.'/catalog/profile', $criteria[9]['evidence'][0]['url']);
        $this->assertStringContainsString('123.45', $criteria[9]['evidence'][0]['excerpt']);
        $this->assertSame(self::ORIGIN, $criteria[13]['evidence'][0]['url']);
        $this->assertSame(self::ORIGIN.'/sitemap.xml', $criteria[2]['evidence'][1]['url']);
        $this->assertNull($criteria[15]['score']);
        foreach (range(17, 20) as $id) {
            $this->assertNull($criteria[$id]['score']);
            $this->assertSame('unknown', $criteria[$id]['status']);
            $this->assertFalse($criteria[$id]['in_scope']);
            $this->assertSame([], $criteria[$id]['evidence']);
        }
        $this->assertSame(2, $result['crawled_pages']);
        $this->assertSame([self::ORIGIN, self::ORIGIN.'/catalog/profile'], array_column($result['pages'], 'url'));
        $this->assertTrue($result['ai_access']['sitemap']['valid_xml']);
        $this->assertTrue($result['ai_access']['has_sitemap_link']);
        $this->assertSame(200, $result['ai_access']['llms_txt']['status']);
        $this->assertSame([
            self::ORIGIN.'/robots.txt', self::ORIGIN, self::ORIGIN.'/sitemap.xml',
            self::ORIGIN.'/catalog/profile', self::ORIGIN.'/llms.txt',
        ], Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->all());
        Http::assertSentCount(5);
    }

    public function test_x_robots_header_is_received_from_http_and_blocks_indexing_score_only(): void
    {
        $this->fakeSite([
            self::ORIGIN => Http::response($this->fixture('home.html'), 200, [
                'Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex, nofollow',
            ]),
        ]);

        $result = app(SiteInspector::class)->inspect(self::ORIGIN, 2);
        $criteria = collect($result['criteria'])->keyBy('id');

        $this->assertSame(1.0, $criteria[1]['score']);
        $this->assertSame(0.0, $criteria[3]['score']);
        $this->assertSame('fail', $criteria[3]['status']);
        $this->assertStringContainsString('ограничения индексации', $criteria[3]['explanation']);
        $this->assertSame(1.0, $criteria[8]['score']);
        Http::assertSentCount(5);
    }

    #[DataProvider('invalidSitemaps')]
    public function test_rejected_or_empty_sitemap_never_confirms_discovery(string $sitemap): void
    {
        $this->fakeSite([
            self::ORIGIN => Http::response('<html><body><p>Страница без ссылок.</p></body></html>', 200, ['Content-Type' => 'text/html']),
            self::ORIGIN.'/sitemap.xml' => Http::response($sitemap, 200, ['Content-Type' => 'application/xml']),
        ]);

        $result = app(SiteInspector::class)->inspect(self::ORIGIN, 1);
        $criterion = collect($result['criteria'])->firstWhere('id', 2);

        $this->assertFalse($result['ai_access']['sitemap']['valid_xml']);
        $this->assertNull($criterion['score']);
        $this->assertSame('unknown', $criterion['status']);
        $this->assertSame([], $criterion['evidence']);
        Http::assertSentCount(4);
    }

    public static function invalidSitemaps(): array
    {
        return [
            'empty body' => [''],
            'malformed XML' => ['<urlset><url>'],
            'HTML response' => ['<html><body>Not a sitemap</body></html>'],
            'external entity rejected' => ['<!DOCTYPE urlset [<!ENTITY xxe SYSTEM "https://never-request.example.com/private">]><urlset>&xxe;</urlset>'],
        ];
    }

    public function test_unavailable_robots_keeps_no_data_unknown_and_never_fetches_html(): void
    {
        Http::fake([self::ORIGIN.'/robots.txt' => Http::failedConnection()]);

        $result = app(SiteInspector::class)->inspect(self::ORIGIN, 2);

        $this->assertCount(20, $result['criteria']);
        $this->assertSame([], $result['pages']);
        $this->assertSame(0, $result['crawled_pages']);
        $this->assertSame(['lower' => 0.0, 'upper' => 100.0, 'coverage' => 0.0, 'display' => '0–100'], $result['readiness']);
        foreach ($result['criteria'] as $criterion) {
            $this->assertNull($criterion['score']);
            $this->assertSame('unknown', $criterion['status']);
        }
        Http::assertNotSent(fn ($request): bool => $request->url() !== self::ORIGIN.'/robots.txt');
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/fixtures/'.$name);
    }

    private function fakeSite(array $overrides = []): void
    {
        Http::fake(array_replace([
            self::ORIGIN.'/robots.txt' => Http::response($this->fixture('robots.txt'), 200, ['Content-Type' => 'text/plain']),
            self::ORIGIN => Http::response($this->fixture('home.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            self::ORIGIN.'/sitemap.xml' => Http::response($this->fixture('sitemap.xml'), 200, ['Content-Type' => 'application/xml']),
            self::ORIGIN.'/catalog/profile' => Http::response($this->fixture('product.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            self::ORIGIN.'/llms.txt' => Http::response($this->fixture('llms.txt'), 200, ['Content-Type' => 'text/plain']),
        ], $overrides));
    }
}
