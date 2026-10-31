<?php

namespace Tests\Feature\Shell\Brands;

use App\Models\Brand;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Brands\Contracts\DomainDnsResolver;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tenancy\TenancyTestCase;

final class BrandSettingsTest extends TenancyTestCase
{
    private FakeBrandDomainDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.E1-05.defaults.external_checks' => true]);
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->dns = new FakeBrandDomainDnsResolver;
        $this->app->instance(DomainDnsResolver::class, $this->dns);
    }

    public function test_owner_reads_target_without_changing_selected_brand(): void
    {
        [$owner, , $selected, $target] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $selected->id])
            ->get($this->url($target))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shell/Brands/Settings')->where('brand.id', $selected->id)
            ->where('targetBrand.id', $target->id)->has('domains', 0)
            ->where('permissions.manage', true));
        self::assertSame($selected->id, session('brand_id'));
        Http::assertNothingSent();
    }

    public function test_general_update_preserves_unrelated_settings_and_selected_brand(): void
    {
        [$owner, $organization, $selected, $target] = $this->cabinet();
        DB::table('brands')->where('id', $target->id)->update(['settings' => json_encode([
            'unrelated' => ['retained' => true], 'theme' => ['subtitle' => 'Прежняя подпись', 'promo_card' => false],
        ], JSON_THROW_ON_ERROR)]);
        $this->actingAs($owner)->withSession(['brand_id' => $selected->id])->patch($this->url($target).'/general', [
            'name' => 'Обновлённый бренд', 'website' => 'https://shop.example.com', 'industry' => 'Кондитерские изделия',
            'market' => 'Россия', 'language' => 'ru', 'timezone' => 'Europe/Moscow', 'aliases' => ['Торты', 'Cake'],
        ])->assertRedirect($this->url($target))->assertSessionHasNoErrors();
        $row = DB::table('brands')->where('id', $target->id)->first();
        self::assertSame('Обновлённый бренд', $row->name);
        self::assertSame('https://shop.example.com', $row->website);
        $settings = json_decode($row->settings, true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($settings['unrelated']['retained']);
        self::assertSame('Прежняя подпись', $settings['theme']['subtitle']);
        self::assertSame(['Торты', 'Cake'], $settings['aliases']);
        self::assertSame('A', DB::table('brands')->where('id', $selected->id)->value('name'));
        self::assertSame($selected->id, session('brand_id'));
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'brand_id' => $target->id, 'action' => 'brand.settings.general_updated', 'subject_id' => $target->id]);
        Http::assertNothingSent();
    }

    public function test_general_validation_rejects_credentials_and_invalid_timezone_without_mutation(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->patch($this->url($brand).'/general', [
            'name' => '', 'website' => 'https://user:secret@example.com', 'language' => 'ru', 'timezone' => 'Not/AZone', 'aliases' => [],
        ])->assertSessionHasErrors(['name', 'timezone']);
        $this->patch($this->url($brand).'/general', [
            'name' => 'Не сохранять', 'website' => 'https://user:secret@example.com', 'language' => 'ru', 'timezone' => 'Europe/Moscow', 'aliases' => [],
        ])->assertSessionHasErrors('website');
        self::assertSame('A', DB::table('brands')->where('id', $brand->id)->value('name'));
        Http::assertNothingSent();
    }

    public function test_member_cannot_read_or_mutate_brand_settings(): void
    {
        [, $organization, $brand] = $this->cabinet();
        [$member] = $this->member($organization, 'member', [$brand->id]);
        $this->actingAs($member)->withSession(['brand_id' => $brand->id])->get($this->url($brand))->assertForbidden();
        $this->patch($this->url($brand).'/general', ['name' => 'Denied'])->assertForbidden();
        $this->patch($this->url($brand).'/theme', ['theme' => 'neutral'])->assertForbidden();
        $this->post($this->url($brand).'/domains', $this->domainInput())->assertForbidden();
        $this->post($this->url($brand).'/website/check')->assertForbidden();
        self::assertSame('A', DB::table('brands')->where('id', $brand->id)->value('name'));
        $this->assertDatabaseCount('brand_domains', 0);
        Http::assertNothingSent();
    }

    public function test_restricted_admin_and_foreign_tenant_ids_fail_closed(): void
    {
        [, $organization, $selected, $other] = $this->cabinet();
        [, , $foreign] = $this->cabinet(false);
        [$admin] = $this->member($organization, 'admin', [$selected->id]);
        $this->actingAs($admin)->withSession(['brand_id' => $selected->id])->get($this->url($selected))->assertOk();
        foreach ([$other, $foreign] as $target) {
            $this->get($this->url($target))->assertNotFound();
            $this->patch($this->url($target).'/general', ['name' => 'Denied', 'language' => 'ru', 'timezone' => 'Europe/Moscow'])->assertNotFound();
            $this->post($this->url($target).'/domains', $this->domainInput())->assertNotFound();
        }
        $this->assertDatabaseCount('brand_domains', 0);
        Http::assertNothingSent();
    }

    public function test_theme_settings_persist_and_do_not_overwrite_other_settings(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode(['aliases' => ['Синоним'], 'unrelated' => 'retained'], JSON_THROW_ON_ERROR)]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->patch($this->url($brand).'/theme', [
            'theme' => 'neutral', 'subtitle' => 'Подпись бренда', 'promo_card' => false,
        ])->assertRedirect($this->url($brand))->assertSessionHasNoErrors();
        $row = DB::table('brands')->where('id', $brand->id)->first();
        $settings = json_decode($row->settings, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('neutral', $row->theme);
        self::assertSame('Подпись бренда', $settings['theme']['subtitle']);
        self::assertFalse($settings['theme']['promo_card']);
        self::assertSame(['Синоним'], $settings['aliases']);
        self::assertSame('retained', $settings['unrelated']);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'action' => 'brand.settings.theme_updated']);
    }

    public function test_theme_validation_rejects_unknown_theme_long_subtitle_and_active_image_content(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->patch($this->url($brand).'/theme', [
            'theme' => 'light', 'subtitle' => str_repeat('А', 61), 'promo_card' => true,
        ])->assertSessionHasErrors(['theme', 'subtitle']);
        $this->post($this->url($brand).'/theme', [
            '_method' => 'PATCH', 'theme' => 'neutral', 'subtitle' => '', 'promo_card' => true,
            'logo' => UploadedFile::fake()->createWithContent('unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ])->assertSessionHasErrors('logo');
        self::assertSame('confectionery', DB::table('brands')->where('id', $brand->id)->value('theme'));
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_uploaded_logo_is_private_and_removable(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post($this->url($brand).'/theme', [
            '_method' => 'PATCH', 'theme' => 'pyro', 'subtitle' => 'Бренд', 'promo_card' => true,
            'logo' => UploadedFile::fake()->image('logo.png', 128, 128),
        ])->assertSessionHasNoErrors();
        $settings = $this->settings($brand);
        self::assertNotEmpty($settings['logo_path']);
        Storage::disk('local')->assertExists($settings['logo_path']);
        $page = $this->get($this->url($brand))->assertOk()->viewData('page')['props'];
        $logoUrl = $page['targetBrand']['logo_url'];
        self::assertIsString($logoUrl);
        $this->get($logoUrl)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->patch($this->url($brand).'/theme', ['theme' => 'pyro', 'subtitle' => 'Бренд', 'promo_card' => true, 'remove_logo' => true])->assertSessionHasNoErrors();
        self::assertNull($this->settings($brand)['logo_path'] ?? null);
        Storage::disk('local')->assertMissing($settings['logo_path']);
    }

    public function test_domain_registration_is_pending_and_returns_server_issued_instructions_without_network(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $domain = $this->addDomain($brand);
        self::assertSame('pending', $domain->status);
        self::assertNull($domain->verified_at);
        // R-S-10 / donor WidgetController: proof uses the plain server-issued token.
        self::assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $domain->verification_token);
        $this->get($this->url($brand))->assertInertia(fn (Assert $page) => $page
            ->has('domains', 1)->where('domains.0.host', 'shop.example.com')
            ->where('domains.0.verification.file_content', $domain->verification_token)
            ->where('domains.0.verification.dns_name', '_eyon.shop.example.com')
            ->missing('domains.0.verification_token'));
        $audit = DB::table('audit_logs')->where('action', 'brand.domain.added')->where('organization_id', $organization->id)->first();
        self::assertNotNull($audit);
        self::assertStringNotContainsString($domain->verification_token, json_encode($audit, JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
        self::assertSame([], $this->dns->calls);
    }

    public function test_failed_logo_audit_keeps_old_logo_and_removes_uncommitted_upload(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post($this->url($brand).'/theme', [
            '_method' => 'PATCH', 'theme' => 'pyro', 'subtitle' => 'Прежняя подпись', 'promo_card' => true,
            'logo' => UploadedFile::fake()->image('old.png', 128, 128),
        ])->assertSessionHasNoErrors();
        $oldPath = $this->settings($brand)['logo_path'];
        $audit = \Mockery::mock(AuditWriter::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit unavailable'));
        $this->app->instance(AuditWriter::class, $audit);
        $this->withoutExceptionHandling();
        try {
            $this->post($this->url($brand).'/theme', [
                '_method' => 'PATCH', 'theme' => 'neutral', 'subtitle' => 'Не сохранять', 'promo_card' => false,
                'logo' => UploadedFile::fake()->image('new.png', 128, 128),
            ]);
            self::fail('Audit failure must abort logo replacement');
        } catch (\RuntimeException $error) {
            self::assertSame('audit unavailable', $error->getMessage());
        }
        self::assertSame($oldPath, $this->settings($brand)['logo_path']);
        self::assertSame('pyro', DB::table('brands')->where('id', $brand->id)->value('theme'));
        Storage::disk('local')->assertExists($oldPath);
        self::assertSame([$oldPath], Storage::disk('local')->allFiles());
    }

    #[DataProvider('unsafeHosts')]
    public function test_invalid_domain_input_never_reaches_network(string $host): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post($this->url($brand).'/domains', $this->domainInput(['host' => $host]))->assertSessionHasErrors('host');
        $this->assertDatabaseCount('brand_domains', 0);
        Http::assertNothingSent();
        self::assertSame([], $this->dns->calls);
    }

    public static function unsafeHosts(): array
    {
        return [
            'URL instead of host' => ['https://example.com'], 'loopback literal' => ['127.0.0.1'],
            'IPv6 literal' => ['[::1]'], 'localhost' => ['localhost'], 'credentials' => ['user@example.com'],
            'path' => ['example.com/private'], 'port' => ['example.com:8080'], 'wildcard' => ['*.example.com'],
        ];
    }

    public function test_domain_method_purpose_and_duplicate_are_validated(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post($this->url($brand).'/domains', $this->domainInput([
            'purpose' => 'arbitrary', 'verification_method' => 'email',
        ]))->assertSessionHasErrors(['purpose', 'verification_method']);
        $this->addDomain($brand);
        $this->post($this->url($brand).'/domains', $this->domainInput())->assertSessionHasErrors('host');
        $this->assertDatabaseCount('brand_domains', 1);
        Http::assertNothingSent();
    }

    public function test_file_verification_uses_https_exact_token_and_audits_without_token(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $domain = $this->addDomain($brand);
        $options = [];
        Http::fake(function ($request, $requestOptions) use ($domain, &$options) {
            $options = $requestOptions;

            return Http::response($domain->verification_token, 200);
        });
        $this->post($this->url($brand).'/domains/'.$domain->id.'/verify')->assertRedirect($this->url($brand))->assertSessionHasNoErrors();
        $verified = DB::table('brand_domains')->where('id', $domain->id)->first();
        self::assertSame('verified', $verified->status);
        self::assertNotNull($verified->verified_at);
        self::assertNotNull($verified->last_checked_at);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://shop.example.com/.well-known/eyon-domain-verification.txt' && $request->method() === 'GET');
        self::assertFalse($options['allow_redirects']);
        self::assertGreaterThan(0, $options['timeout']);
        self::assertLessThanOrEqual(15, $options['timeout']);
        self::assertGreaterThan(0, $options['connect_timeout']);
        self::assertLessThanOrEqual(10, $options['connect_timeout']);
        self::assertContains('shop.example.com:443:93.184.216.34', $options['curl'][CURLOPT_RESOLVE]);
        // Validate the actual cURL transport options without executing the handle or sending a request.
        $factory = new CurlFactory(0);
        $handle = null;
        try {
            $handle = $factory->create(new Request('GET', 'https://shop.example.com/.well-known/eyon-domain-verification.txt'), $options);
            self::assertInstanceOf(\CurlHandle::class, $handle->handle);
        } finally {
            if ($handle !== null) {
                $factory->release($handle);
            }
            $factory->close();
        }
        $audit = DB::table('audit_logs')->where('organization_id', $organization->id)->where('action', 'brand.domain.verified')->first();
        self::assertNotNull($audit);
        self::assertStringNotContainsString($domain->verification_token, json_encode($audit, JSON_THROW_ON_ERROR));
    }

    public function test_stale_verification_result_cannot_overwrite_a_newer_check(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $domain = $this->addDomain($brand);
        $newVersion = (int) $domain->check_version + 1;
        Http::fake(function () use ($domain, $newVersion) {
            // Simulate a newer completed check while the first HTTP response is still pending.
            DB::table('brand_domains')->where('id', $domain->id)->update([
                'check_version' => $newVersion, 'status' => 'verified', 'verified_at' => now(),
                'last_checked_at' => now(), 'last_error' => null,
            ]);

            return Http::response('stale-invalid-token', 200);
        });
        $this->post($this->url($brand).'/domains/'.$domain->id.'/verify')->assertStatus(409);
        $this->assertDatabaseHas('brand_domains', [
            'id' => $domain->id, 'check_version' => $newVersion, 'status' => 'verified', 'last_error' => null,
        ]);
        self::assertNotNull(DB::table('brand_domains')->where('id', $domain->id)->value('verified_at'));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'brand.domain.verification_failed', 'subject_id' => $domain->id]);
        Http::assertSentCount(1);
    }

    #[DataProvider('failedFileResponses')]
    public function test_redirect_http_error_and_wrong_body_never_verify(int $status, string $body, array $headers): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $domain = $this->addDomain($brand);
        Http::fake(['https://shop.example.com/*' => Http::response($body, $status, $headers)]);
        $this->post($this->url($brand).'/domains/'.$domain->id.'/verify')->assertRedirect($this->url($brand));
        $this->assertDatabaseHas('brand_domains', ['id' => $domain->id, 'status' => 'failed', 'verified_at' => null]);
        self::assertNotEmpty(DB::table('brand_domains')->where('id', $domain->id)->value('last_error'));
        Http::assertSentCount(1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'brand.domain.verification_failed', 'subject_id' => $domain->id]);
    }

    public static function failedFileResponses(): array
    {
        return [
            'redirect to private host' => [302, '', ['Location' => 'http://127.0.0.1/admin']],
            'not found' => [404, 'missing', []], 'wrong body' => [200, 'not-the-token', []],
        ];
    }

    public function test_transport_failure_is_recorded_without_unhandled_exception(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $domain = $this->addDomain($brand);
        Http::fake(fn () => throw new ConnectionException('synthetic timeout'));
        $this->post($this->url($brand).'/domains/'.$domain->id.'/verify')->assertRedirect($this->url($brand));
        $this->assertDatabaseHas('brand_domains', ['id' => $domain->id, 'status' => 'failed', 'verified_at' => null]);
    }

    #[DataProvider('nonPublicAddresses')]
    public function test_private_or_mixed_dns_results_are_rejected_before_http(array $addresses): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $domain = $this->addDomain($brand);
        $this->dns->addressResults = $addresses;
        Http::fake();
        $this->post($this->url($brand).'/domains/'.$domain->id.'/verify')->assertRedirect($this->url($brand));
        $this->assertDatabaseHas('brand_domains', ['id' => $domain->id, 'status' => 'failed', 'verified_at' => null]);
        Http::assertNothingSent();
    }

    public static function nonPublicAddresses(): array
    {
        return [
            'loopback' => [['127.0.0.1']], 'private' => [['10.0.0.1']], 'metadata' => [['169.254.169.254']],
            'IPv6 loopback' => [['::1']], 'mapped loopback' => [['::ffff:127.0.0.1']],
            'mixed public and private' => [['93.184.216.34', '192.168.1.10']], 'unresolved' => [[]],
        ];
    }

    public function test_dns_verification_matches_server_token_without_http(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $domain = $this->addDomain($brand, ['verification_method' => 'dns']);
        $this->dns->txtResults = ['unrelated record', $domain->verification_token];
        $this->post($this->url($brand).'/domains/'.$domain->id.'/verify')->assertRedirect($this->url($brand))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('brand_domains', ['id' => $domain->id, 'status' => 'verified']);
        self::assertContains(['txt', '_eyon.shop.example.com'], $this->dns->calls);
        Http::assertNothingSent();
    }

    public function test_wrong_dns_token_cannot_verify_and_failed_retry_clears_verified_state(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $domain = $this->addDomain($brand, ['verification_method' => 'dns']);
        $this->dns->txtResults = [$domain->verification_token];
        $this->post($this->url($brand).'/domains/'.$domain->id.'/verify')->assertSessionHasNoErrors();
        $this->dns->txtResults = ['prefix-'.$domain->verification_token.'-suffix'];
        $this->post($this->url($brand).'/domains/'.$domain->id.'/verify')->assertRedirect($this->url($brand));
        $this->assertDatabaseHas('brand_domains', ['id' => $domain->id, 'status' => 'failed', 'verified_at' => null]);
        Http::assertNothingSent();
    }

    public function test_domain_id_cannot_cross_brand_or_organization_boundaries(): void
    {
        [$owner, , $selected, $target] = $this->cabinet();
        [, $foreignOrganization, $foreignBrand] = $this->cabinet(false);
        $this->actingAs($owner)->withSession(['brand_id' => $selected->id]);
        $domain = $this->addDomain($target);
        $this->post($this->url($selected).'/domains/'.$domain->id.'/verify')->assertNotFound();
        $this->delete($this->url($selected).'/domains/'.$domain->id)->assertNotFound();
        $foreignDomainId = (string) Str::ulid();
        DB::table('brand_domains')->insert(array_replace((array) $domain, [
            'id' => $foreignDomainId, 'organization_id' => $foreignOrganization->id, 'brand_id' => $foreignBrand->id,
            'host' => 'foreign.example.com',
        ]));
        $this->post($this->url($selected).'/domains/'.$foreignDomainId.'/verify')->assertNotFound();
        $this->delete($this->url($selected).'/domains/'.$foreignDomainId)->assertNotFound();
        $this->post($this->url($foreignBrand).'/domains/'.$foreignDomainId.'/verify')->assertNotFound();
        $this->assertDatabaseHas('brand_domains', ['id' => $domain->id, 'brand_id' => $target->id, 'status' => 'pending']);
        Http::assertNothingSent();
    }

    public function test_domain_removal_is_audited_and_does_not_touch_other_domains(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $first = $this->addDomain($brand);
        $second = $this->addDomain($brand, ['host' => 'other.example.com']);
        $this->delete($this->url($brand).'/domains/'.$first->id)->assertRedirect($this->url($brand))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('brand_domains', ['id' => $first->id]);
        $this->assertDatabaseHas('brand_domains', ['id' => $second->id]);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'action' => 'brand.domain.removed', 'subject_id' => $first->id]);
        Http::assertNothingSent();
    }

    public function test_audit_failure_rolls_back_general_change(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $audit = \Mockery::mock(AuditWriter::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit unavailable'));
        $this->app->instance(AuditWriter::class, $audit);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withoutExceptionHandling();
        try {
            $this->patch($this->url($brand).'/general', ['name' => 'Rolled back', 'language' => 'ru', 'timezone' => 'Europe/Moscow', 'aliases' => []]);
            self::fail('Audit failure must abort mutation');
        } catch (\RuntimeException $error) {
            self::assertSame('audit unavailable', $error->getMessage());
        }
        self::assertSame('A', DB::table('brands')->where('id', $brand->id)->value('name'));
    }

    public function test_website_check_persists_availability_and_robots_denial_with_fake_http(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        DB::table('brands')->where('id', $brand->id)->update(['website' => 'https://shop.example.com/']);
        Http::fake([
            'https://shop.example.com/' => Http::response('<html><title>Fixture</title></html>', 200),
            'https://shop.example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /\n", 200),
        ]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post($this->url($brand).'/website/check')->assertRedirect($this->url($brand))->assertSessionHasNoErrors();
        $check = $this->settings($brand)['website_check'];
        self::assertSame('available', $check['status']);
        self::assertTrue($check['robots_blocked']);
        self::assertNotEmpty($check['checked_at']);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'action' => 'brand.website.checked']);
        Http::assertSentCount(2);
    }

    public function test_website_check_rejects_private_dns_without_http_and_requires_saved_website(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post($this->url($brand).'/website/check')->assertSessionHasErrors('website');
        DB::table('brands')->where('id', $brand->id)->update(['website' => 'https://shop.example.com/']);
        $this->dns->addressResults = ['127.0.0.1'];
        Http::fake();
        $this->post($this->url($brand).'/website/check')->assertRedirect($this->url($brand));
        self::assertSame('unavailable', $this->settings($brand)['website_check']['status']);
        Http::assertNothingSent();
    }

    public function test_external_checks_are_default_off_without_changing_existing_results(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $file = $this->addDomain($brand);
        $dns = $this->addDomain($brand, ['host' => 'other.example.com', 'verification_method' => 'dns']);
        DB::table('brand_domains')->whereIn('id', [$file->id, $dns->id])->update([
            'status' => 'verified', 'verified_at' => now(), 'last_checked_at' => now(), 'check_version' => 3,
        ]);
        $previous = ['status' => 'available', 'checked_at' => now()->toIso8601String(), 'robots_blocked' => false, 'error' => null];
        DB::table('brands')->where('id', $brand->id)->update(['website' => 'https://shop.example.com/', 'settings' => json_encode(['website_check' => $previous], JSON_THROW_ON_ERROR)]);
        $before = DB::table('brand_domains')->whereIn('id', [$file->id, $dns->id])->orderBy('host')->get()->toArray();
        $audits = DB::table('audit_logs')->where('organization_id', $organization->id)->count();
        config(['features.E1-05.defaults.external_checks' => false]);
        $this->get($this->url($brand))->assertInertia(fn (Assert $page) => $page->where('networkChecksEnabled', false));
        foreach ([$file, $dns] as $domain) {
            $this->post($this->url($brand).'/domains/'.$domain->id.'/verify')->assertRedirect($this->url($brand))->assertSessionHasErrors('domain');
        }
        $this->post($this->url($brand).'/website/check')->assertRedirect($this->url($brand))->assertSessionHasErrors('website');
        self::assertEquals($before, DB::table('brand_domains')->whereIn('id', [$file->id, $dns->id])->orderBy('host')->get()->toArray());
        self::assertEquals($previous, $this->settings($brand)['website_check']);
        self::assertArrayNotHasKey('website_check_revision', $this->settings($brand));
        self::assertSame($audits, DB::table('audit_logs')->where('organization_id', $organization->id)->count());
        self::assertSame([], $this->dns->calls);
        Http::assertNothingSent();
    }

    public function test_disabled_checks_do_not_bypass_brand_permissions(): void
    {
        [, $organization, $brand] = $this->cabinet();
        [$member] = $this->member($organization, 'member', [$brand->id]);
        config(['features.E1-05.defaults.external_checks' => false]);
        $this->actingAs($member)->withSession(['brand_id' => $brand->id]);
        $this->post($this->url($brand).'/website/check')->assertForbidden();
        $this->post($this->url($brand).'/domains/'.Str::ulid().'/verify')->assertForbidden();
        self::assertSame([], $this->dns->calls);
        Http::assertNothingSent();
    }

    public function test_concurrent_website_check_cannot_replace_first_completed_result(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        DB::table('brands')->where('id', $brand->id)->update(['website' => 'https://shop.example.com/']);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $calls = 0;
        Http::fake(function () use (&$calls, $brand) {
            $calls++;
            if ($calls === 1) {
                $this->post($this->url($brand).'/website/check')->assertRedirect($this->url($brand))->assertSessionHasNoErrors();

                return Http::response('stale', 500);
            }

            return Http::response('new', 503);
        });
        $this->post($this->url($brand).'/website/check')->assertStatus(409);
        self::assertSame(2, $calls);
        self::assertSame('Сайт не вернул успешный ответ. Перенаправления автоматически не выполняются.', $this->settings($brand)['website_check']['error']);
        self::assertSame(1, DB::table('audit_logs')->where('organization_id', $organization->id)->where('action', 'brand.website.checked')->count());
    }

    public function test_website_aba_change_invalidates_inflight_check(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        DB::table('brands')->where('id', $brand->id)->update(['website' => 'https://shop.example.com/']);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        Http::fake(function () use ($brand) {
            foreach (['https://other.example.com/', 'https://shop.example.com/'] as $website) {
                $this->patch($this->url($brand).'/general', ['name' => 'A', 'website' => $website])->assertSessionHasNoErrors();
            }

            return Http::response('stale', 500);
        });
        $this->post($this->url($brand).'/website/check')->assertStatus(409);
        self::assertSame('https://shop.example.com/', DB::table('brands')->where('id', $brand->id)->value('website'));
        self::assertArrayNotHasKey('website_check', $this->settings($brand));
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'action' => 'brand.website.checked']);
    }

    public function test_card_module_values_follow_existing_plan_enforcement(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        $plan = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => 'brands-test-'.Str::lower(Str::random(8)), 'name' => 'Fixture', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => 'modules.seller', 'value' => '0', 'unit' => '', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->put('/brands/'.$brand->id.'/modules/seller', ['enabled' => true])->assertSessionHasErrors('enabled');
        $this->get($this->url($brand))->assertInertia(fn (Assert $page) => $page
            ->where('moduleItems.seller.allowed', false)->where('moduleItems.seller.enabled', false)
            ->where('moduleItems.vision.allowed', true)->where('moduleItems.vision.quota.configured', false));
    }

    private function url(Brand $brand): string
    {
        return '/brands/'.$brand->id.'/settings';
    }

    private function domainInput(array $overrides = []): array
    {
        return array_replace(['host' => 'shop.example.com', 'purpose' => 'widget', 'verification_method' => 'file'], $overrides);
    }

    private function addDomain(Brand $brand, array $overrides = []): object
    {
        $input = $this->domainInput($overrides);
        $this->post($this->url($brand).'/domains', $input)->assertRedirect($this->url($brand))->assertSessionHasNoErrors();

        return DB::table('brand_domains')->where('brand_id', $brand->id)->where('host', $input['host'])->sole();
    }

    private function settings(Brand $brand): array
    {
        return json_decode(DB::table('brands')->where('id', $brand->id)->value('settings'), true, flags: JSON_THROW_ON_ERROR);
    }
}

final class FakeBrandDomainDnsResolver implements DomainDnsResolver
{
    public array $addressResults = ['93.184.216.34'];

    public array $txtResults = [];

    public array $calls = [];

    public function addresses(string $host): array
    {
        $this->calls[] = ['addresses', $host];

        return $this->addressResults;
    }

    public function txtRecords(string $host): array
    {
        $this->calls[] = ['txt', $host];

        return $this->txtResults;
    }
}
