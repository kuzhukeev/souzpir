<?php

namespace Tests\Feature\Vision\KnowledgeBridge;

use App\Ai\CanonicalJson;
use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use App\Vision\KnowledgeBridge\DenyGrantAuthority;
use App\Vision\KnowledgeBridge\GrantAuthority;
use App\Vision\KnowledgeBridge\PublishedKnowledgeBridge;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PublishedKnowledgeBridgeTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(GrantAuthority::class, new class implements GrantAuthority
        {
            public function assertActive(TenantContext $tenant, string $grantId, string $releaseId, string $policyChecksum): void {}
        });
        self::assertSame(config('runtime.test_database'), DB::connection()->getDatabaseName());
        self::assertNotSame(config('runtime.preview_database'), DB::connection()->getDatabaseName());
        if (! Schema::hasTable('measurements')) {
            Schema::create('measurements', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('organization_id');
                $table->ulid('brand_id');
                $table->jsonb('profile_snapshot')->nullable();
                $table->string('profile_checksum', 64)->nullable();
            });
        }
    }

    private function source(string $fact = 'Широкий ассортимент'): array
    {
        return ['conditions' => [], 'facts' => [['id' => 'assortment', 'facts' => [$fact, 'Остальной внутренний факт']]], 'triggers' => [], 'guard_rules' => [], 'policy' => [], 'intents' => [], 'escalation' => [], 'persona' => [], 'fewshot' => [], 'closed_topics' => []];
    }

    private function fixture(string $fact = 'Широкий ассортимент'): array
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $member->id);

        return app(TenantContextRunner::class)->run($ctx, function () use ($ctx, $owner, $org, $brand, $other, $member, $fact): array {
            $store = app(DatabaseKnowledgeRepository::class);
            $draft = $store->createDraft($ctx, $this->source($fact));
            $published = $store->publish($ctx, $draft['id'], 1);
            $rows = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')->where('g.release_id', $published->releaseId())->orderBy('f.position')->select('f.*')->get();
            $grantId = (string) Str::ulid();

            return compact('ctx', 'owner', 'org', 'brand', 'other', 'member', 'draft', 'published', 'rows', 'grantId');
        });
    }

    private function grant(array $fixture, ?array $ids = null): void
    {
        DB::table('brand_modules')->updateOrInsert(['organization_id' => $fixture['org']->id, 'brand_id' => $fixture['brand']->id, 'module' => 'vision'], ['id' => (string) Str::ulid(), 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $settings = ['onboarding' => ['aliases' => ['Бренд А']], 'ai_data_policy' => ['knowledge_public' => ['any']], 'vision_knowledge' => ['enabled' => true, 'grant_id' => $fixture['grantId'], 'release_id' => $fixture['published']->releaseId(), 'allowed_fact_ids' => $ids ?? [$fixture['rows'][0]->logical_id], 'data_classes' => ['knowledge_public']]];
        DB::table('brands')->where('id', $fixture['brand']->id)->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR)]);
    }

    private function alterSettings(array $fixture, \Closure $change): void
    {
        $raw = DB::table('brands')->where('id', $fixture['brand']->id)->value('settings');
        $settings = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $settings = $change($settings);
        DB::table('brands')->where('id', $fixture['brand']->id)->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR)]);
    }

    private function frozen(array $fixture, ?\Closure $storedChange = null, ?string $checksumOverride = null): array
    {
        return app(TenantContextRunner::class)->run($fixture['ctx'], function () use ($fixture, $storedChange, $checksumOverride): array {
            return DB::transaction(function () use ($fixture, $storedChange, $checksumOverride): array {
                $profile = app(PublishedKnowledgeBridge::class)->buildProfile($fixture['ctx']);
                $id = (string) Str::ulid();
                $stored = $storedChange ? $storedChange($profile) : $profile;
                $row = ['id' => $id, 'organization_id' => $fixture['ctx']->organizationId, 'brand_id' => $fixture['ctx']->brandId, 'profile_snapshot' => json_encode($stored, JSON_THROW_ON_ERROR), 'profile_checksum' => $checksumOverride ?? CanonicalJson::hash($stored)];
                if (Schema::hasColumn('measurements', 'question_set_id')) {
                    $set = (string) Str::ulid();
                    DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $fixture['ctx']->organizationId, 'brand_id' => $fixture['ctx']->brandId, 'version' => DB::table('question_sets')->where('brand_id', $fixture['ctx']->brandId)->count() + 1, 'questions' => '[{"text":"Вопрос"}]', 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
                    $row += ['question_set_id' => $set, 'status' => 'pending', 'configuration' => '{}', 'created_at' => now(), 'updated_at' => now()];
                }
                DB::table('measurements')->insert($row);

                return [$id, $profile];
            });
        });
    }

    private function gate(array $fixture, string $id, array $profile): void
    {
        app(TenantContextRunner::class)->run($fixture['ctx'], fn () => app(KnowledgeGate::class)->assertAllowed($fixture['ctx'], $id, $profile));
    }

    private function denied(\Closure $operation): void
    {
        try {
            $operation();
            self::fail('Expected fail-closed rejection');
        } catch (LogicException $e) {
            self::assertSame('The approved knowledge profile is unavailable.', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function test_explicit_grant_builds_canonical_projected_profile_and_provenance(): void
    {
        $f = $this->fixture();
        $this->denied(fn () => app(TenantContextRunner::class)->run($f['ctx'], fn () => DB::transaction(fn () => app(PublishedKnowledgeBridge::class)->buildProfile($f['ctx']))));
        $this->grant($f);
        [$id, $profile] = $this->frozen($f);
        self::assertSame('A', $profile['name']);
        self::assertSame(['Бренд А'], $profile['aliases']);
        self::assertSame([['id' => $f['rows'][0]->logical_id, 'topic' => 'knowledge_fact', 'value' => 'Широкий ассортимент', 'verifiable' => false, 'source' => 'manual']], $profile['facts']);
        self::assertSame($f['published']->releaseId(), $profile['knowledge']['release_id']);
        self::assertSame($f['published']->checksum(), $profile['knowledge']['checksum']);
        self::assertSame(['knowledge_public'], $profile['knowledge']['data_classes']);
        $this->gate($f, $id, $profile);
        self::assertStringNotContainsString('Остальной внутренний факт', json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function test_untrusted_profile_and_persisted_tampering_fail_closed(): void
    {
        $f = $this->fixture();
        $this->grant($f);
        [$id, $profile] = $this->frozen($f);
        foreach (['name', 'aliases', 'release', 'checksum', 'fact', 'extra', 'classes'] as $case) {
            $bad = $profile;
            match ($case) {
                'name' => $bad['name'] = 'Чужой бренд',
                'aliases' => $bad['aliases'][] = 'Чужой',
                'release' => $bad['knowledge']['release_id'] = (string) Str::ulid(),
                'checksum' => $bad['knowledge']['checksum'] = str_repeat('0', 64),
                'fact' => $bad['facts'][0]['value'] = 'Подмена',
                'classes' => $bad['knowledge']['data_classes'][] = 'contacts',
                'extra' => $bad['hidden_contact'] = 'secret@example.test',
            };
            $this->denied(fn () => $this->gate($f, $id, $bad));
        }
        [$broken, $original] = $this->frozen($f, null, str_repeat('0', 64));
        $this->denied(fn () => $this->gate($f, $broken, $original));
        [$forged, $original] = $this->frozen($f, function ($p) {
            $p['name'] = 'Чужой бренд';

            return $p;
        });
        $this->denied(fn () => $this->gate($f, $forged, $original));
    }

    public function test_revocation_and_live_policy_are_rechecked_for_every_use(): void
    {
        $f = $this->fixture();
        $this->grant($f);
        [$id, $profile] = $this->frozen($f);
        $this->gate($f, $id, $profile);
        $this->alterSettings($f, function ($s) {
            $s['vision_knowledge']['allowed_fact_ids'] = [];

            return $s;
        });
        $this->denied(fn () => $this->gate($f, $id, $profile));
        $this->grant($f);
        $this->alterSettings($f, function ($s) {
            $s['vision_knowledge']['enabled'] = false;

            return $s;
        });
        $this->denied(fn () => $this->gate($f, $id, $profile));
        $this->grant($f);
        DB::table('brand_modules')->where('brand_id', $f['brand']->id)->where('module', 'vision')->update(['enabled' => false]);
        $this->denied(fn () => $this->gate($f, $id, $profile));
        $this->grant($f);
        $this->alterSettings($f, function ($s) {
            unset($s['ai_data_policy']['knowledge_public']);

            return $s;
        });
        $this->denied(fn () => $this->gate($f, $id, $profile));
        $this->grant($f);
        app(TenantContextRunner::class)->run($f['ctx'], function () use ($f, $id, $profile): void {
            DB::table('brands')->where('id', $f['brand']->id)->update(['status' => 'archived']);
            $this->denied(fn () => app(KnowledgeGate::class)->assertAllowed($f['ctx'], $id, $profile));
        });
    }

    public function test_other_tenant_and_changed_release_cannot_reuse_snapshot(): void
    {
        $f = $this->fixture();
        $this->grant($f);
        [$id, $profile] = $this->frozen($f);
        $otherCtx = new TenantContext($f['org']->id, $f['other']->id, $f['owner']->id, $f['member']->id);
        $this->denied(fn () => app(TenantContextRunner::class)->run($otherCtx, fn () => app(KnowledgeGate::class)->assertAllowed($otherCtx, $id, $profile)));
        app(TenantContextRunner::class)->run($f['ctx'], function () use ($f): void {
            app(DatabaseKnowledgeRepository::class)->publish($f['ctx'], $f['draft']['id'], 2);
        });
        $this->denied(fn () => $this->gate($f, $id, $profile));
    }

    public function test_contacts_in_allowed_fact_or_brand_alias_are_rejected(): void
    {
        $f = $this->fixture('Напишите test@example.test');
        $this->grant($f);
        $this->denied(fn () => $this->frozen($f));
        $f2 = $this->fixture();
        $this->grant($f2);
        $this->alterSettings($f2, function ($s) {
            $s['onboarding']['aliases'] = ['+7 999 123-45-67'];

            return $s;
        });
        $this->denied(fn () => $this->frozen($f2));
    }

    public function test_unknown_inactive_duplicate_or_foreign_fact_ids_fail_closed(): void
    {
        $f = $this->fixture();
        $this->grant($f, [(string) Str::ulid()]);
        $this->denied(fn () => $this->frozen($f));
        $this->grant($f, [$f['rows'][0]->logical_id, $f['rows'][0]->logical_id]);
        $this->denied(fn () => $this->frozen($f));
        $f2 = $this->fixture();
        $this->grant($f, [$f2['rows'][0]->logical_id]);
        $this->denied(fn () => $this->frozen($f));
        app(TenantContextRunner::class)->run($f['ctx'], function () use ($f): void {
            $store = app(DatabaseKnowledgeRepository::class);
            $editor = $store->draftEditor($f['ctx'], $f['draft']['id']);
            $fact = $editor['groups'][0]['facts'][0];
            $version = $store->setFact($f['ctx'], $f['draft']['id'], $fact['id'], 2, $fact['text'], 'inactive', 'import');
            $second = $store->publish($f['ctx'], $f['draft']['id'], $version);
            $f['published'] = $second;
            $this->grant($f, [$fact['logical_id']]);
            $this->denied(fn () => DB::transaction(fn () => app(PublishedKnowledgeBridge::class)->buildProfile($f['ctx'])));
        });
    }

    public function test_session_access_is_rechecked_inside_open_context(): void
    {
        $f = $this->fixture();
        $this->grant($f);
        [$id, $profile] = $this->frozen($f);
        app(TenantContextRunner::class)->run($f['ctx'], function () use ($f, $id, $profile): void {
            DB::table('memberships')->where('id', $f['member']->id)->update(['status' => 'disabled']);
            $this->denied(fn () => app(KnowledgeGate::class)->assertAllowed($f['ctx'], $id, $profile));
        });
    }

    public function test_job_context_is_tenant_scoped(): void
    {
        $f = $this->fixture();
        $this->grant($f);
        [$id, $profile] = $this->frozen($f);
        $job = new TenantContext($f['org']->id, $f['brand']->id, source: 'job');
        app(TenantContextRunner::class)->run($job, function () use ($job, $id, $profile): void {
            app(KnowledgeGate::class)->assertAllowed($job, $id, $profile);
        });

    }

    public function test_deny_authority_denies_even_if_settings_appear_to_allow_access(): void
    {
        $f = $this->fixture();
        $this->grant($f);
        $this->app->instance(GrantAuthority::class, new DenyGrantAuthority);
        $this->denied(fn () => $this->frozen($f));
    }

    public function test_new_grant_does_not_resurrect_an_earlier_frozen_profile(): void
    {
        $f = $this->fixture();
        $this->grant($f);
        [$id, $profile] = $this->frozen($f);
        $f['grantId'] = (string) Str::ulid();
        $this->grant($f);
        $this->denied(fn () => $this->gate($f, $id, $profile));
    }

    public function test_revoked_authority_blocks_unchanged_snapshot(): void
    {
        $f = $this->fixture();
        $this->grant($f);
        [$id, $profile] = $this->frozen($f);
        $this->app->instance(GrantAuthority::class, new class implements GrantAuthority
        {
            public function assertActive(TenantContext $tenant, string $grantId, string $releaseId, string $policyChecksum): void
            {
                throw new LogicException('revoked');
            }
        });
        $this->denied(fn () => $this->gate($f, $id, $profile));
    }

    public function test_plan_disabling_vision_blocks_previously_valid_snapshot(): void
    {
        $f = $this->fixture();
        $this->grant($f);
        [$id, $profile] = $this->frozen($f);
        $plan = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => 'e801d-'.Str::lower(Str::random(10)), 'name' => 'Synthetic', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $f['org']->id, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => 'modules.vision', 'value' => '0', 'unit' => '', 'created_at' => now(), 'updated_at' => now()]);
        $this->denied(fn () => $this->gate($f, $id, $profile));
    }

    public function test_foreign_measurement_and_forged_context_are_rejected(): void
    {
        $f = $this->fixture();
        $this->grant($f);
        [$id, $profile] = $this->frozen($f);
        $foreign = $this->fixture();
        $this->grant($foreign);
        [$otherId] = $this->frozen($foreign);
        $this->denied(fn () => $this->gate($f, $otherId, $profile));
        $spoof = new TenantContext($f['org']->id, $f['brand']->id, $foreign['owner']->id, $foreign['member']->id);
        $this->denied(fn () => app(TenantContextRunner::class)->run($f['ctx'], fn () => app(KnowledgeGate::class)->assertAllowed($spoof, $id, $profile)));
    }

    public function test_equal_text_facts_use_authorized_logical_id_not_string_matching(): void
    {
        $f = $this->fixture('Остальной внутренний факт');
        self::assertNotSame($f['rows'][0]->logical_id, $f['rows'][1]->logical_id);
        $this->grant($f, [$f['rows'][1]->logical_id]);
        [$id, $profile] = $this->frozen($f);
        self::assertCount(1, $profile['facts']);
        self::assertSame($f['rows'][1]->logical_id, $profile['facts'][0]['id']);
        $this->gate($f, $id, $profile);
    }

    public function test_contact_failure_does_not_leak_raw_value_in_exception_chain(): void
    {
        $secret = 'secret-address@example.test';
        $f = $this->fixture('Напишите '.$secret);
        $this->grant($f);
        try {
            $this->frozen($f);
            self::fail('Contact accepted');
        } catch (LogicException $error) {
            self::assertStringNotContainsString($secret, $error->getMessage().$error->getTraceAsString());
            self::assertNull($error->getPrevious());
        }
    }
}
