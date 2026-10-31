<?php

namespace Tests\Feature\Vision\KnowledgeBridge;

use App\Ai\CanonicalJson;
use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use App\Vision\KnowledgeBridge\GrantAuthority;
use App\Vision\KnowledgeBridge\PublishedKnowledgeBridge;
use App\Vision\KnowledgeGrants\DatabaseGrantAuthority;
use App\Vision\KnowledgeGrants\GrantRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Tenancy\TenancyTestCase;

/** Exercises the real registry, bridge, repository and frozen measurement together. */
final class GrantBridgeIntegrationTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(config('runtime.test_database'), DB::connection()->getDatabaseName());
        self::assertNotSame(config('runtime.preview_database'), DB::connection()->getDatabaseName());
        // Core owns the final schema; its migration is not yet integrated.
        if (! Schema::hasTable('measurements')) {
            Schema::create('measurements', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('organization_id');
                $table->ulid('brand_id');
                $table->jsonb('profile_snapshot')->nullable();
                $table->string('profile_checksum', 64)->nullable();
            });
        }
        self::assertInstanceOf(DatabaseGrantAuthority::class, app(GrantAuthority::class));
    }

    private function fixture(): array
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $member->id);

        return app(TenantContextRunner::class)->run($ctx, function () use ($ctx, $org, $brand, $other): array {
            $source = ['conditions' => [], 'facts' => [['id' => 'assortment', 'facts' => ['Широкий ассортимент', 'Внутренний факт']]], 'triggers' => [], 'guard_rules' => [], 'policy' => [], 'intents' => [], 'escalation' => [], 'persona' => [], 'fewshot' => [], 'closed_topics' => []];
            $store = app(DatabaseKnowledgeRepository::class);
            $draft = $store->createDraft($ctx, $source);
            $release = $store->publish($ctx, $draft['id'], 1);
            $fact = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')->where('g.release_id', $release->releaseId())->orderBy('f.position')->value('f.logical_id');
            DB::table('brand_modules')->updateOrInsert(['organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision'], ['id' => (string) Str::ulid(), 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
            $grant = app(GrantRegistry::class)->issue($ctx, $release->releaseId(), [$fact]);

            return compact('ctx', 'org', 'brand', 'other', 'draft', 'release', 'fact', 'grant');
        });
    }

    private function freeze(array $f): array
    {
        return app(TenantContextRunner::class)->run($f['ctx'], fn () => DB::transaction(function () use ($f): array {
            $profile = app(PublishedKnowledgeBridge::class)->buildProfile($f['ctx']);
            $id = (string) Str::ulid();
            $row = ['id' => $id, 'organization_id' => $f['org']->id, 'brand_id' => $f['brand']->id, 'profile_snapshot' => json_encode($profile, JSON_THROW_ON_ERROR), 'profile_checksum' => CanonicalJson::hash($profile)];
            if (Schema::hasColumn('measurements', 'question_set_id')) {
                $set = (string) Str::ulid();
                DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $f['org']->id, 'brand_id' => $f['brand']->id, 'version' => DB::table('question_sets')->where('brand_id', $f['brand']->id)->count() + 1, 'questions' => '[{"text":"Вопрос"}]', 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
                $row += ['question_set_id' => $set, 'status' => 'pending', 'configuration' => '{}', 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('measurements')->insert($row);

            return [$id, $profile];
        }));
    }

    private function check(TenantContext $ctx, string $id, array $profile): void
    {
        app(TenantContextRunner::class)->run($ctx, fn () => app(KnowledgeGate::class)->assertAllowed($ctx, $id, $profile));
    }

    private function denied(\Closure $operation): void
    {
        try {
            $operation();
            self::fail('Expected fail-closed rejection.');
        } catch (LogicException $e) {
            self::assertSame('The approved knowledge profile is unavailable.', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function test_real_grant_builds_and_rechecks_only_authorized_facts(): void
    {
        $f = $this->fixture();
        [$id, $profile] = $this->freeze($f);
        self::assertSame($f['grant']['id'], $profile['knowledge']['grant_id']);
        self::assertSame($f['grant']['policy_checksum'], $profile['knowledge']['policy_checksum']);
        self::assertSame([$f['fact']], array_column($profile['facts'], 'id'));
        self::assertStringNotContainsString('Внутренний факт', json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->check($f['ctx'], $id, $profile);
        $tampered = $profile;
        $tampered['facts'][0]['value'] = 'Подмена';
        $this->denied(fn () => $this->check($f['ctx'], $id, $tampered));
        DB::table('measurements')->where('id', $id)->update(['profile_checksum' => str_repeat('0', 64)]);
        $this->denied(fn () => $this->check($f['ctx'], $id, $profile));
    }

    public function test_revocation_and_reissue_do_not_resurrect_frozen_snapshot(): void
    {
        $f = $this->fixture();
        [$id, $profile] = $this->freeze($f);
        app(TenantContextRunner::class)->run($f['ctx'], function () use ($f): void {
            self::assertTrue(app(GrantRegistry::class)->revoke($f['ctx'], $f['grant']['id']));
        });
        $this->denied(fn () => $this->check($f['ctx'], $id, $profile));
        app(TenantContextRunner::class)->run($f['ctx'], function () use ($f): void {
            $new = app(GrantRegistry::class)->issue($f['ctx'], $f['release']->releaseId(), [$f['fact']]);
            self::assertNotSame($f['grant']['id'], $new['id']);
        });
        $this->denied(fn () => $this->check($f['ctx'], $id, $profile));
        [$newId, $newProfile] = $this->freeze($f);
        $this->check($f['ctx'], $newId, $newProfile);
    }

    public function test_publish_and_changed_policy_deny_old_snapshot(): void
    {
        $f = $this->fixture();
        [$id, $profile] = $this->freeze($f);
        $settings = json_decode(DB::table('brands')->where('id', $f['brand']->id)->value('settings'), true, flags: JSON_THROW_ON_ERROR);
        $settings['vision_knowledge']['allowed_fact_ids'] = [];
        try {
            DB::transaction(fn () => DB::table('brands')->where('id', $f['brand']->id)->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR)]));
            self::fail('Active grant must protect its policy.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
        $this->check($f['ctx'], $id, $profile);
        app(TenantContextRunner::class)->run($f['ctx'], function () use ($f): void {
            app(GrantRegistry::class)->issue($f['ctx'], $f['release']->releaseId(), []);
        });
        $this->denied(fn () => $this->check($f['ctx'], $id, $profile));
        [$newId, $newProfile] = $this->freeze($f);
        self::assertSame([], $newProfile['facts']);
        $this->check($f['ctx'], $newId, $newProfile);
        app(TenantContextRunner::class)->run($f['ctx'], fn () => app(DatabaseKnowledgeRepository::class)->publish($f['ctx'], $f['draft']['id'], 2));
        self::assertNotNull(DB::table('vision_knowledge_grants')->where('id', $newProfile['knowledge']['grant_id'])->value('revoked_at'));
        $this->denied(fn () => $this->check($f['ctx'], $newId, $newProfile));
    }

    public function test_other_brand_and_organization_cannot_use_frozen_profile(): void
    {
        $f = $this->fixture();
        [$id, $profile] = $this->freeze($f);
        $otherBrand = new TenantContext($f['ctx']->organizationId, $f['other']->id, $f['ctx']->actorId, $f['ctx']->membershipId);
        $this->denied(fn () => $this->check($otherBrand, $id, $profile));
        $foreign = $this->fixture();
        [$foreignId] = $this->freeze($foreign);
        $this->denied(fn () => $this->check($foreign['ctx'], $id, $profile));
        $this->denied(fn () => $this->check($f['ctx'], $foreignId, $profile));
    }
}
