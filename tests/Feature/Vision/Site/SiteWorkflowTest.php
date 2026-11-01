<?php

namespace Tests\Feature\Vision\Site;

use App\Ai\CanonicalJson;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Vision\Core\ReportSeal;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use App\Vision\SiteAudit\PublicAddressResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SiteWorkflowTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function cabinet(bool $configured = true): array
    {
        $cabinet = parent::cabinet($configured);
        foreach ([$cabinet[2], $cabinet[3]] as $brand) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $cabinet[1]->id,
                'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $cabinet;
    }

    public function test_real_permissions_allow_owner_admin_editor_and_deny_manager_viewer(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        [$admin] = $this->member($organization, 'admin', [$brand->id]);
        [$editor] = $this->member($organization, 'member', [$brand->id]);
        [$manager] = $this->member($organization, 'member', [$brand->id], ['sales_manager' => true]);
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);
        foreach ([$owner, $admin, $editor] as $actor) {
            $this->actingAs($actor)->withSession(['brand_id' => $brand->id])
                ->post('/vision/actions', ['title' => 'Действие'])->assertSessionHasNoErrors()->assertRedirect();
        }
        foreach ([$manager, $viewer] as $actor) {
            $this->actingAs($actor)->withSession(['brand_id' => $brand->id])
                ->post('/vision/actions', ['title' => 'Нет доступа'])->assertForbidden();
            $this->post('/vision/site/audit', ['url' => 'https://fixture.example.com'])->assertForbidden();
        }
        $this->assertDatabaseCount('actions', 3);
        Http::assertNothingSent();
    }

    public function test_actions_crud_completion_validation_and_brand_isolation(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/vision/actions', ['title' => 'Добавить контакты', 'responsible' => $membership->id, 'due_date' => '2026-11-20'])->assertSessionHasNoErrors();
        $row = DB::table('actions')->where('brand_id', $brand->id)->sole();
        self::assertSame('open', $row->status);
        $this->patch('/vision/actions/'.$row->id, ['status' => 'awaiting_measurement'])->assertSessionHasErrors('comment');
        $this->patch('/vision/actions/'.$row->id, ['status' => 'awaiting_measurement', 'completed_at' => '2026-10-31', 'comment' => 'Добавлены контакты', 'evidence_url' => 'https://example.com/proof'])->assertSessionHasNoErrors();
        $this->patch('/vision/actions/'.$row->id, ['completed_at' => null])->assertSessionHasErrors('comment');
        $this->patch('/vision/actions/'.$row->id, ['comment' => null])->assertSessionHasErrors('comment');
        $this->assertDatabaseHas('actions', ['id' => $row->id, 'status' => 'awaiting_measurement']);
        $this->get('/vision/actions/history')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Vision/Site/Index')->where('history.0.id', $row->id)->where('history.0.status', 'awaiting_measurement'));
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->patch('/vision/actions/'.$row->id, ['title' => 'Нет'])->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->patch('/vision/actions/'.$row->id, ['title' => 'Чужой бренд'])->assertNotFound();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->delete('/vision/actions/'.$row->id)->assertRedirect();
        $this->assertDatabaseMissing('actions', ['id' => $row->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'vision.action.created', 'subject_id' => $row->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'vision.action.updated', 'subject_id' => $row->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'vision.action.deleted', 'subject_id' => $row->id]);
    }

    public function test_audit_failure_rolls_back_action_creation(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $audit = \Mockery::mock(AuditWriter::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit unavailable'));
        $this->app->instance(AuditWriter::class, $audit);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withoutExceptionHandling();
        try {
            $this->post('/vision/actions', ['title' => 'Не сохранять']);
            self::fail('Missing audit must roll back.');
        } catch (\RuntimeException $error) {
            self::assertSame('audit unavailable', $error->getMessage());
        }
        $this->assertDatabaseCount('actions', 0);
    }

    public function test_unsafe_url_is_never_flashed_or_sent(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        foreach (['https://example.com/?secret=private', 'https://user:password@example.com/'] as $url) {
            $this->from('/vision/site')->post('/vision/site/audit', ['url' => $url])
                ->assertSessionHasErrors('url')->assertSessionMissing('_old_input.url');
        }
        Http::assertNothingSent();
    }

    public function test_html_fixture_result_is_private_and_no_raw_result_enters_audit_log(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->fakeSite();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/vision/site/audit', ['url' => 'https://fixture.example.com'])->assertSessionHasNoErrors();
        $run = DB::table('audit_runs')->where('brand_id', $brand->id)->sole();
        $result = json_decode($run->result, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('https://fixture.example.com', $result['url']);
        self::assertCount(20, $result['criteria']);
        self::assertNotEmpty($result['pages']);
        $audit = DB::table('audit_logs')->where('organization_id', $org->id)->where('action', 'vision.site.audit.created')->sole();
        self::assertStringNotContainsString('fixture.example.com', json_encode($audit, JSON_THROW_ON_ERROR));
        $this->get('/vision/site')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $page) => $page->where('audit.url', 'https://fixture.example.com'));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/vision/site')->assertOk()->assertInertia(fn (Assert $page) => $page->where('audit', null));
        $this->post('/brand/switch', ['brand_id' => $brand->id])->assertRedirect();
        $this->get('/vision/site')->assertOk()->assertInertia(fn (Assert $page) => $page->where('audit.url', 'https://fixture.example.com'));
    }

    public function test_membership_revocation_during_fake_crawl_prevents_persistence(): void
    {
        [$owner, , $brand, , $membership] = $this->cabinet();
        $this->fakeSite($membership->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/vision/site/audit', ['url' => 'https://fixture.example.com'])->assertForbidden();
        $this->assertDatabaseCount('audit_runs', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'vision.site.audit.created']);
    }

    public function test_session_revocation_during_fake_crawl_prevents_persistence(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->fakeSite(null, $owner->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->postJson('/vision/site/audit', ['url' => 'https://fixture.example.com'])->assertUnauthorized();
        $this->assertDatabaseCount('audit_runs', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'vision.site.audit.created']);
    }

    public function test_history_requires_signed_report_and_rechecks_grant_but_does_not_trust_chronology(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $gate = new class implements KnowledgeGate
        {
            public bool $allowed = true;

            public function assertAllowed(TenantContext $tenant, string $measurementId, array $frozenProfile): void
            {
                if (! $this->allowed) {
                    throw new \LogicException('revoked');
                }
            }
        };
        $this->app->instance(KnowledgeGate::class, $gate);
        $set = $this->questionSet($org->id, $brand->id);
        $oldId = $this->reportFixture($org->id, $brand->id, $set, 'model-a', false, now()->subDays(2));
        $this->reportFixture($org->id, $brand->id, $set, 'model-a', true, now()->subDay());
        // A late revision of the older measurement cannot reverse the chronology.
        $old = DB::table('vision_reports')->where('id', $oldId)->sole();
        foreach (range(2, 23) as $revision) {
            $revisionId = (string) Str::ulid();
            DB::table('vision_reports')->insert(['id' => $revisionId, 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'measurement_id' => $old->measurement_id, 'revision' => $revision, 'payload' => $old->payload, 'checksum' => $old->checksum,
                'snapshot_mac' => ReportSeal::mac($org->id, $brand->id, $revisionId, $old->measurement_id, $revision, $old->checksum),
                'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/actions/history')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('comparison.status', 'too_early')->where('comparison.delta', null)
            ->has('achievements', 1)->where('achievements.0.earned_at', null));
        $this->assertDatabaseCount('brand_achievements', 0);
        $gate->allowed = false;
        $this->get('/vision/actions/history')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('comparison.status', 'too_early')->has('achievements', 0));
    }

    public function test_incompatible_or_unsigned_reports_do_not_prove_effect(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->app->instance(KnowledgeGate::class, new class implements KnowledgeGate
        {
            public function assertAllowed(TenantContext $tenant, string $measurementId, array $frozenProfile): void {}
        });
        $set = $this->questionSet($org->id, $brand->id);
        $this->reportFixture($org->id, $brand->id, $set, 'model-a', false, now()->subDays(2));
        $this->reportFixture($org->id, $brand->id, $set, 'model-b', true, now()->subDay());
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/actions/history')->assertOk()->assertInertia(fn (Assert $page) => $page->where('comparison.status', 'too_early'));
        $this->reportFixture($org->id, $brand->id, $set, 'model-b', true, now(), false);
        $this->get('/vision/actions/history')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('comparison.status', 'too_early')->has('achievements', 0));
    }

    public function test_report_without_measurement_end_time_is_not_ordered_as_evidence(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->app->instance(KnowledgeGate::class, new class implements KnowledgeGate
        {
            public function assertAllowed(TenantContext $tenant, string $measurementId, array $frozenProfile): void {}
        });
        $set = $this->questionSet($org->id, $brand->id);
        $this->reportFixture($org->id, $brand->id, $set, 'model-a', false, now()->subDay());
        $this->reportFixture($org->id, $brand->id, $set, 'model-a', true, now(), true, false);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/actions/history')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('comparison.status', 'too_early')->has('achievements', 0));
    }

    public function test_oversized_plan_cannot_be_used_as_report_evidence(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->app->instance(KnowledgeGate::class, new class implements KnowledgeGate
        {
            public function assertAllowed(TenantContext $tenant, string $measurementId, array $frozenProfile): void {}
        });
        $set = $this->questionSet($org->id, $brand->id);
        $this->reportFixture($org->id, $brand->id, $set, 'model-a', true, now(), true, true, true);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/actions/history')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('comparison.status', 'too_early')->has('achievements', 0));
    }

    private function questionSet(string $organization, string $brand): string
    {
        $id = (string) Str::ulid();
        $questions = [['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']];
        DB::table('question_sets')->insert(['id' => $id, 'organization_id' => $organization, 'brand_id' => $brand,
            'version' => 1, 'questions' => json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'size' => 1, 'checksum' => CanonicalJson::hash($questions), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function reportFixture(string $organization, string $brand, string $set, string $model, bool $mentioned, $at, bool $signed = true, bool $finished = true, bool $oversized = false): string
    {
        $questions = [['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']];
        $configuration = ['questions' => $questions, 'question_set_checksum' => CanonicalJson::hash($questions),
            'providers' => ['provider' => $model], 'runs' => 1, 'temperature' => 0, 'max_output_tokens' => 100, 'parser' => 'v1'];
        if ($oversized) {
            $configuration['padding'] = str_repeat('x', 2000000);
        }
        $measurement = (string) Str::ulid();
        $profile = ['fixture' => 'synthetic'];
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $organization, 'brand_id' => $brand,
            'question_set_id' => $set, 'status' => 'completed', 'configuration' => json_encode($configuration, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'profile_snapshot' => json_encode($profile, JSON_THROW_ON_ERROR), 'profile_checksum' => CanonicalJson::hash($profile), 'finished_at' => $finished ? $at : null,
            'created_at' => $at, 'updated_at' => $at]);
        $slot = ['provider' => 'provider', 'qid' => 'q1', 'question_n' => 1, 'run_no' => 1, 'status' => 'ok',
            'protocol_valid' => true, 'fact' => ['brand_mentioned' => $mentioned, 'mention_status' => $mentioned ? 'confirmed' : 'not_mentioned']];
        $payload = ['measurement_id' => $measurement, 'questions' => $questions, 'question_set_checksum' => CanonicalJson::hash($questions),
            'providers' => $configuration['providers'], 'runs' => 1, 'evidence_status' => 'available',
            'metrics' => ['panel' => ['mentioned' => (int) $mentioned, 'mention_denominator' => 1], 'slots' => [$slot]]];
        $id = (string) Str::ulid();
        $checksum = CanonicalJson::hash($payload);
        DB::table('vision_reports')->insert(['id' => $id, 'organization_id' => $organization, 'brand_id' => $brand,
            'measurement_id' => $measurement, 'revision' => 1, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            'checksum' => $checksum, 'snapshot_mac' => $signed ? ReportSeal::mac($organization, $brand, $id, $measurement, 1, $checksum) : null,
            'created_at' => $at, 'updated_at' => $at]);

        return $id;
    }

    private function fakeSite(?string $revokeMembership = null, ?string $revokeUser = null): void
    {
        config(['features.E8-01B.defaults.fetch_enabled' => true]);
        $this->app->instance(PublicAddressResolver::class, new class implements PublicAddressResolver
        {
            public function addresses(string $host): array
            {
                if ($host !== 'fixture.example.com') {
                    throw new \RuntimeException('Unexpected DNS host.');
                }

                return ['93.184.216.34'];
            }
        });
        Http::fake(function ($request) use ($revokeMembership, $revokeUser) {
            if ($revokeMembership !== null) {
                DB::table('memberships')->where('id', $revokeMembership)->update(['status' => 'disabled']);
            }
            if ($revokeUser !== null) {
                DB::table('auth_session_registry')->where('user_id', $revokeUser)->update(['revoked_at' => now()]);
            }
            if (str_ends_with($request->url(), '/robots.txt')) {
                return Http::response("User-agent: *\nAllow: /\n", 200, ['Content-Type' => 'text/plain']);
            }
            if ($request->url() === 'https://fixture.example.com') {
                return Http::response('<html><body><h1>Тестовое предложение</h1><p>Данные HTML фикстуры.</p></body></html>', 200, ['Content-Type' => 'text/html']);
            }

            return Http::response('', 404, ['Content-Type' => 'text/plain']);
        });
    }
}
