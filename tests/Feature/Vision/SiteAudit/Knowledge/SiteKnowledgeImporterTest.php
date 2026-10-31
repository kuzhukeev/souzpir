<?php

namespace Tests\Feature\Vision\SiteAudit\Knowledge;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\SiteAudit\Knowledge\SiteDraftStore;
use App\Vision\SiteAudit\Knowledge\SiteKnowledgeImporter;
use App\Vision\SiteAudit\PublicAddressResolver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class SiteKnowledgeImporterTest extends TestCase
{
    use DatabaseMigrations;

    private string $home;

    private ?string $redirect = null;

    private ?string $revokeMembership = null;

    private string $robots = "User-agent: *\nAllow: /";

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = file_get_contents(__DIR__.'/Fixtures/home.html');
        config(['features.E8-01B.defaults.fetch_enabled' => true]);
        app()->instance(PublicAddressResolver::class, new class implements PublicAddressResolver
        {
            public function addresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        Http::preventStrayRequests();
        Http::fake(fn ($request) => match ($request->url()) {
            'https://brand.example/robots.txt' => Http::response($this->robots, 200, ['Content-Type' => 'text/plain']),
            'https://brand.example/' => $this->homeResponse(),
            'https://brand.example/details' => Http::response(file_get_contents(__DIR__.'/Fixtures/details.html'), 200, ['Content-Type' => 'text/html']),
            default => throw new RuntimeException('Unexpected request: '.$request->url()),
        });
    }

    private function context(): array
    {
        $owner = $this->user();
        $org = Organization::create(['name' => 'Site import fixture', 'owner_user_id' => $owner->id]);
        [$membership, $first, $second] = app(TenantContextRunner::class)->run(new TenantContext($org->id, source: 'job'), fn () => [
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']),
            Brand::create(['name' => 'A', 'theme' => 'neutral']),
            Brand::create(['name' => 'B', 'theme' => 'neutral']),
        ]);
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        DB::table('brands')->whereIn('id', [$first->id, $second->id])->update(['website' => 'https://brand.example/']);

        return [new TenantContext($org->id, $first->id, $owner->id, $membership->id), $org, $second, $membership];
    }

    private function user(): User
    {
        return User::create(['name' => 'Fixture actor', 'email' => Str::lower(Str::random(12)).'@example.test', 'password' => 'test-only-password']);
    }

    private function member(Organization $organization, string $role, array $brandIds): array
    {
        $user = $this->user();
        $membership = app(TenantContextRunner::class)->run(new TenantContext($organization->id, source: 'job'),
            fn () => Membership::create(['user_id' => $user->id, 'role' => $role, 'brand_ids' => $brandIds]));

        return [$user, $membership];
    }

    private function homeResponse(): mixed
    {
        if ($this->revokeMembership !== null) {
            DB::table('memberships')->where('id', $this->revokeMembership)->update(['status' => 'disabled']);
        }

        return $this->redirect === null ? Http::response($this->home, 200, ['Content-Type' => 'text/html'])
            : Http::response('', 302, ['Location' => $this->redirect]);
    }

    public function test_hidden_site_content_and_links_never_enter_the_private_draft_or_crawl(): void
    {
        [$tenant] = $this->context();
        $this->home = <<<'HTML'
<html><body><h1>Публичное предложение</h1><p>Видимое описание продукции и условий компании.</p>
<section hidden><p>HIDDEN_ONE</p><a href="/hidden-one">Ссылка</a></section>
<section aria-hidden="true"><p>HIDDEN_TWO</p><a href="/hidden-two">Ссылка</a></section>
<section style="display:none"><p>HIDDEN_THREE</p><a href="/hidden-three">Ссылка</a></section>
<section style="visibility:hidden"><p>HIDDEN_FOUR</p><a href="/hidden-four">Ссылка</a></section>
</body></html>
HTML;
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $result = app(SiteKnowledgeImporter::class)->import($tenant);
            self::assertSame([], $result['errors']);
            self::assertSame(1, $result['pages']);
            $draft = app(SiteDraftStore::class)->read($tenant, 'https://brand.example/');
            self::assertSame('draft', $draft['status']);
            self::assertStringContainsString('Видимое описание', $draft['content_text']);
            self::assertStringNotContainsString('HIDDEN_', $draft['content_text']);
            self::assertSame(0, $result['confirmed']);
        });
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/hidden-'));
    }

    public function test_private_drafts_preserve_text_and_price_caveats_and_reuse_unchanged_versions(): void
    {
        [$tenant] = $this->context();
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $importer = app(SiteKnowledgeImporter::class);
            $result = $importer->import($tenant);
            self::assertSame([], $result['errors']);
            self::assertSame(2, $result['pages']);
            self::assertSame(2, $result['documents_created']);
            self::assertSame(2, $result['versions_created']);
            self::assertSame(0, $result['confirmed']);
            self::assertFalse($result['limited']);
            $store = app(SiteDraftStore::class);
            $home = $store->read($tenant, 'https://brand.example/');
            $details = $store->read($tenant, 'https://brand.example/details');
            self::assertSame('draft', $home['status']);
            self::assertStringContainsString('Услуга | Срок', $home['content_text']);
            self::assertStringContainsString('info@example.test', $home['content_text']);
            self::assertStringNotContainsString('SECRET_', $home['content_text']);
            self::assertStringContainsString('Вопрос: Как заказать?', $details['content_text']);
            self::assertStringContainsString('Фрагмент кода (пример):', $details['content_text']);
            self::assertSame(['Условия на https://brand.example/: Цены ориентировочные, итоговая стоимость требует уточнения.'], $details['price_caveats']);
            $repeat = $importer->import($tenant);
            self::assertSame(2, $repeat['versions_reused']);
            self::assertSame(0, $repeat['versions_created']);
            self::assertSame($result['sources'][0]['version_id'], $repeat['sources'][0]['version_id']);
            self::assertSame(2, DB::table('audit_logs')->where('action', 'vision.site_source.draft_stored')->count());
            $this->home = str_replace('Публичные условия', 'Обновлённые условия', $this->home);
            $changed = $importer->import($tenant);
            self::assertSame(1, $changed['versions_created']);
            self::assertSame(1, $changed['versions_reused']);
            $latest = $store->read($tenant, 'https://brand.example/');
            self::assertSame(2, $latest['version']);
            self::assertSame($home['document_id'], $latest['document_id']);
            self::assertSame($home['fingerprint'], $latest['previous_fingerprint']);
            self::assertNull(DB::table('brands')->where('id', $tenant->brandId)->value('active_knowledge_release_id'));
            DB::table('brands')->where('id', $tenant->brandId)->update(['website' => 'https://new-brand.example/']);
            self::assertSame($latest['version_id'], $store->read($tenant, 'https://brand.example/')['version_id']);
        });
        Http::assertSentCount(9);
    }

    public function test_same_website_is_isolated_between_brands_and_forged_context_is_denied(): void
    {
        [$first, , $brand] = $this->context();
        $second = new TenantContext($first->organizationId, $brand->id, $first->actorId, $first->membershipId);
        $one = app(TenantContextRunner::class)->run($first, fn () => app(SiteKnowledgeImporter::class)->import($first, maxPages: 1));
        app(TenantContextRunner::class)->run($second, function () use ($second, $first, $one): void {
            self::assertNull(app(SiteDraftStore::class)->read($second, 'https://brand.example/'));
            $two = app(SiteKnowledgeImporter::class)->import($second, maxPages: 1);
            self::assertNotSame($one['sources'][0]['document_id'], $two['sources'][0]['document_id']);
            $this->expectException(LogicException::class);
            app(SiteDraftStore::class)->read($first, 'https://brand.example/');
        });
    }

    public function test_viewer_and_unassigned_member_cannot_crawl_or_read_drafts(): void
    {
        [$owner, $org, $sibling] = $this->context();
        foreach ([['viewer', [$owner->brandId]], ['admin', [$sibling->id]]] as [$role, $brands]) {
            [$user, $membership] = $this->member($org, $role, $brands);
            $tenant = new TenantContext($org->id, $owner->brandId, $user->id, $membership->id);
            try {
                app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
                    foreach ([fn () => app(SiteKnowledgeImporter::class)->import($tenant), fn () => app(SiteDraftStore::class)->read($tenant, 'https://brand.example/')] as $operation) {
                        try {
                            $operation();
                            self::fail('Unauthorized source access.');
                        } catch (LogicException) {
                            self::assertTrue(true);
                        }
                    }
                });
            } catch (LogicException) {
                // The tenant resolver itself rejects a brand outside the membership.
                self::assertSame('admin', $role);
            }
        }
        Http::assertNothingSent();
    }

    public function test_robots_and_page_limit_are_enforced_without_fetching_disallowed_pages(): void
    {
        [$tenant] = $this->context();
        $this->robots = "User-agent: *\nDisallow: /details";
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $result = app(SiteKnowledgeImporter::class)->import($tenant);
            self::assertSame(1, $result['pages']);
            self::assertCount(1, $result['errors']);
            self::assertStringContainsString('robots.txt', $result['errors'][0]['message']);
            $limited = app(SiteKnowledgeImporter::class)->import($tenant, maxPages: 1);
            self::assertTrue($limited['limited']);
        });
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/details'));
    }

    public function test_limits_and_foreign_origin_are_rejected_before_http(): void
    {
        [$tenant] = $this->context();
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            foreach ([[null, 0], [null, 101], ['https://other.example/', 20], ['https://brand.example/?q=1', 20]] as [$url, $limit]) {
                try {
                    app(SiteKnowledgeImporter::class)->import($tenant, $url, $limit);
                    self::fail('Invalid crawl accepted.');
                } catch (InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        });
        Http::assertNothingSent();
    }

    public function test_failed_audit_does_not_expose_or_reuse_a_draft(): void
    {
        [$tenant] = $this->context();
        app()->instance(AuditWriter::class, new class implements AuditWriter
        {
            public function record(TenantContext $context, AuditEntry $entry): void
            {
                throw new RuntimeException('Audit unavailable.');
            }
        });
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $result = app(SiteKnowledgeImporter::class)->import($tenant, maxPages: 1);
            self::assertSame(0, $result['pages']);
            self::assertCount(1, $result['errors']);
            self::assertNull(app(SiteDraftStore::class)->read($tenant, 'https://brand.example/'));
            self::assertSame(0, DB::table('audit_logs')->where('action', 'vision.site_source.draft_stored')->count());
        });
    }

    public function test_foreign_redirect_is_not_followed(): void
    {
        [$tenant] = $this->context();
        $this->redirect = 'https://other.example/private';
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $result = app(SiteKnowledgeImporter::class)->import($tenant);
            self::assertSame(0, $result['pages']);
            self::assertCount(1, $result['errors']);
            self::assertStringContainsString('Переадресация', $result['errors'][0]['message']);
        });
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'other.example'));
    }

    public function test_revoked_membership_during_fetch_cannot_persist_source(): void
    {
        [$owner, $org] = $this->context();
        [$user, $membership] = $this->member($org, 'admin', [$owner->brandId]);
        $tenant = new TenantContext($org->id, $owner->brandId, $user->id, $membership->id);
        $this->revokeMembership = $membership->id;
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $result = app(SiteKnowledgeImporter::class)->import($tenant);
            self::assertSame(0, $result['pages']);
            self::assertCount(1, $result['errors']);
            self::assertSame(0, DB::table('audit_logs')->where('action', 'vision.site_source.draft_stored')->count());
        });
        Http::assertSentCount(2);
    }

    public function test_caller_transaction_is_rejected_before_http(): void
    {
        [$tenant] = $this->context();
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            try {
                DB::transaction(fn () => app(SiteKnowledgeImporter::class)->import($tenant));
                self::fail('Network import started inside transaction.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        Http::assertNothingSent();
    }

    public function test_draft_store_does_not_release_file_lock_before_outer_transaction_commits(): void
    {
        [$tenant] = $this->context();
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $page = ['title' => 'Example', 'text' => 'Source text for review.', 'fingerprint' => str_repeat('a', 64), 'price_caveats' => []];
            $this->expectException(LogicException::class);
            DB::transaction(fn () => app(SiteDraftStore::class)->store($tenant, 'https://brand.example/', 'https://brand.example/', $page, 20));
        });
    }

    public function test_direct_store_rejects_foreign_source_and_cannot_reuse_forged_fingerprint(): void
    {
        [$tenant] = $this->context();
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $page = ['title' => 'Example', 'text' => 'Source text for review.', 'fingerprint' => str_repeat('a', 64), 'price_caveats' => []];
            $store = app(SiteDraftStore::class);
            try {
                $store->store($tenant, 'https://brand.example/', 'https://other.example/', $page, 20);
                self::fail('Foreign source stored.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
            $first = $store->store($tenant, 'https://brand.example/', 'https://brand.example/', $page, 20);
            $page['text'] = 'Changed source text with the same supplied fingerprint.';
            $second = $store->store($tenant, 'https://brand.example/', 'https://brand.example/', $page, 20);
            self::assertNotSame($first['version_id'], $second['version_id']);
            self::assertSame(1, $second['versions_created']);
            self::assertSame($page['text'], $store->read($tenant, 'https://brand.example/')['content_text']);
        });
        Http::assertNothingSent();
    }

    public function test_uncommitted_orphan_revision_is_not_returned_or_reused(): void
    {
        [$tenant] = $this->context();
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $result = app(SiteKnowledgeImporter::class)->import($tenant, maxPages: 1);
            $store = app(SiteDraftStore::class);
            $original = $store->read($tenant, 'https://brand.example/');
            $directory = storage_path('app/private/vision/site-audit/'.$tenant->organizationId.'/'.$tenant->brandId.'/'.hash('sha256', 'https://brand.example/'));
            $orphan = [...$original, 'version_id' => (string) Str::ulid(), 'audit_event_id' => (string) Str::ulid(), 'version' => 2, 'content_text' => 'Uncommitted text'];
            File::put($directory.'/'.$orphan['version_id'].'.json', json_encode($orphan, JSON_THROW_ON_ERROR));
            File::put($directory.'/manifest.json', json_encode(['versions' => [$original['version_id'], $orphan['version_id']]], JSON_THROW_ON_ERROR));
            self::assertSame($original['version_id'], $store->read($tenant, 'https://brand.example/')['version_id']);
            self::assertSame($result['sources'][0]['version_id'], app(SiteKnowledgeImporter::class)->import($tenant, maxPages: 1)['sources'][0]['version_id']);
        });
    }
}
