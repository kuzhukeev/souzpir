<?php

namespace Tests\Feature\Vision\KnowledgeGrants;

use App\Ai\CanonicalJson;
use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\KnowledgeBridge\GrantAuthority;
use App\Vision\KnowledgeGrants\DatabaseGrantAuthority;
use App\Vision\KnowledgeGrants\GrantRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Seller\Knowledge\Fixtures\PrototypeKnowledge;
use Tests\Feature\Tenancy\TenancyTestCase;

if (! interface_exists(GrantAuthority::class)) {
    require_once __DIR__.'/Fixtures/GrantAuthority.php';
}

final class GrantRegistryTest extends TenancyTestCase
{
    private function data(): array
    {
        $source = new PrototypeKnowledge(base_path('tests/Feature/Seller/Knowledge/Fixtures/data'), 0);
        $data = [];
        foreach (PrototypeKnowledge::FILES as $file) {
            $data[$file] = $source->all($file);
        }

        return $data;
    }

    private function release(TenantContext $ctx): array
    {
        $store = app(DatabaseKnowledgeRepository::class);
        $draft = $store->createDraft($ctx, $this->data());
        $first = $store->publish($ctx, $draft['id'], 1);
        DB::table('brands')->where('id', $ctx->brandId)->update(['settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
        $fact = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')
            ->where('g.release_id', $first->releaseId())->where('f.status', 'active')->value('f.logical_id');

        return [$draft, $first->releaseId(), $fact];
    }

    public function test_issue_revocation_reissue_and_database_immutability(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($ctx, function () use ($ctx): void {
            [, $release, $fact] = $this->release($ctx);
            $registry = app(GrantRegistry::class);
            $first = $registry->issue($ctx, $release, [$fact]);
            $settings = json_decode(DB::table('brands')->where('id', $ctx->brandId)->value('settings'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($first['id'], $settings['vision_knowledge']['grant_id']);
            self::assertSame($first['policy_checksum'], CanonicalJson::hash($settings['vision_knowledge']));
            self::assertSame(['any'], $settings['ai_data_policy']['knowledge_public']);
            DB::transaction(fn () => app(DatabaseGrantAuthority::class)->assertActive($ctx, $first['id'], $release, $first['policy_checksum']));
            self::assertTrue($registry->revoke($ctx, $first['id']));
            self::assertFalse($registry->revoke($ctx, $first['id']));
            $second = $registry->issue($ctx, $release, [$fact]);
            self::assertNotSame($first['id'], $second['id']);
            try {
                DB::transaction(fn () => app(DatabaseGrantAuthority::class)->assertActive($ctx, $first['id'], $release, $first['policy_checksum']));
                self::fail('Revoked grant was accepted.');
            } catch (LogicException) {
                self::assertNotNull(DB::table('vision_knowledge_grants')->where('id', $first['id'])->value('revoked_at'));
            }
            foreach ([
                fn () => DB::table('vision_knowledge_grants')->where('id', $first['id'])->update(['revoked_at' => null, 'revoked_by' => null, 'revocation_reason' => null]),
                fn () => DB::table('vision_knowledge_grants')->where('id', $first['id'])->delete(),
                fn () => DB::statement('TRUNCATE vision_knowledge_grants CASCADE'),
                fn () => DB::table('vision_knowledge_grants')->where('id', $second['id'])->update(['policy_checksum' => str_repeat('a', 64)]),
            ] as $attack) {
                try {
                    DB::transaction($attack);
                    self::fail('Grant history changed.');
                } catch (QueryException) {
                    self::assertTrue(true);
                }
            }
            self::assertSame(2, DB::table('audit_logs')->where('action', 'vision.knowledge.grant.issued')->count());
            self::assertSame(1, DB::table('audit_logs')->where('action', 'vision.knowledge.grant.revoked')->count());
        });
    }

    public function test_policy_cannot_be_switched_off_and_restored_without_revoking_the_grant(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($ctx, function () use ($ctx): void {
            [, $release, $fact] = $this->release($ctx);
            $registry = app(GrantRegistry::class);
            $grant = $registry->issue($ctx, $release, [$fact]);
            $settings = json_decode(DB::table('brands')->where('id', $ctx->brandId)->value('settings'), true, flags: JSON_THROW_ON_ERROR);
            $mutated = $settings;
            $mutated['vision_knowledge']['enabled'] = false;
            $changedConsent = $settings;
            $changedConsent['ai_data_policy']['knowledge_public'] = [];
            foreach ([$mutated, $changedConsent] as $value) {
                try {
                    DB::transaction(fn () => DB::table('brands')->where('id', $ctx->brandId)->update(['settings' => json_encode($value, JSON_THROW_ON_ERROR)]));
                    self::fail('A live grant survived a policy change.');
                } catch (QueryException) {
                    self::assertNull(DB::table('vision_knowledge_grants')->where('id', $grant['id'])->value('revoked_at'));
                }
            }
            self::assertTrue($registry->revoke($ctx, $grant['id']));
            DB::table('brands')->where('id', $ctx->brandId)->update(['settings' => json_encode($changedConsent, JSON_THROW_ON_ERROR)]);
            try {
                DB::transaction(fn () => app(DatabaseGrantAuthority::class)->assertActive($ctx, $grant['id'], $release, $grant['policy_checksum']));
                self::fail('Restoring settings revived a revoked grant.');
            } catch (LogicException) {
                self::assertNotNull(DB::table('vision_knowledge_grants')->where('id', $grant['id'])->value('revoked_at'));
            }
        });
    }

    public function test_regrant_revokes_old_id_and_audits_both_changes_atomically(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($ctx, function () use ($ctx): void {
            [, $release, $fact] = $this->release($ctx);
            $first = app(GrantRegistry::class)->issue($ctx, $release, [$fact]);
            $second = app(GrantRegistry::class)->issue($ctx, $release, [$fact]);
            self::assertNotSame($first['id'], $second['id']);
            self::assertSame('replaced', DB::table('vision_knowledge_grants')->where('id', $first['id'])->value('revocation_reason'));
            self::assertNull(DB::table('vision_knowledge_grants')->where('id', $second['id'])->value('revoked_at'));
            self::assertSame(2, DB::table('audit_logs')->where('action', 'vision.knowledge.grant.issued')->count());
            self::assertSame(1, DB::table('audit_logs')->where('action', 'vision.knowledge.grant.revoked')->count());
        });
    }

    public function test_publish_and_rollback_revoke_old_grants_without_reviving_them(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($ctx, function () use ($ctx): void {
            [$draft, $firstRelease, $fact] = $this->release($ctx);
            $store = app(DatabaseKnowledgeRepository::class);
            $registry = app(GrantRegistry::class);
            $first = $registry->issue($ctx, $firstRelease, [$fact]);
            $secondRelease = $store->publish($ctx, $draft['id'], 2)->releaseId();
            $row = DB::table('vision_knowledge_grants')->where('id', $first['id'])->first();
            self::assertNotNull($row->revoked_at);
            self::assertSame('release_changed', $row->revocation_reason);
            $second = $registry->issue($ctx, $secondRelease, [$fact]);
            $store->rollback($ctx, $firstRelease);
            self::assertNotNull(DB::table('vision_knowledge_grants')->where('id', $second['id'])->value('revoked_at'));
            self::assertNotNull(DB::table('vision_knowledge_grants')->where('id', $first['id'])->value('revoked_at'));
            $third = $registry->issue($ctx, $firstRelease, [$fact]);
            $store->rollback($ctx, $firstRelease);
            self::assertNull(DB::table('vision_knowledge_grants')->where('id', $third['id'])->value('revoked_at'));
            self::assertSame(2, DB::table('audit_logs')->where('action', 'vision.knowledge.grant.revoked')->count());
        });
    }

    public function test_wrong_tenant_role_policy_and_direct_pointer_change_fail_closed(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        [$editor, $editorMembership] = $this->member($org, 'member', [$brand->id]);
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        [$release, $grant] = app(TenantContextRunner::class)->run($ctx, function () use ($ctx): array {
            [, $release, $fact] = $this->release($ctx);
            $grant = app(GrantRegistry::class)->issue($ctx, $release, [$fact]);
            foreach ([str_repeat('0', 64), strtoupper($grant['policy_checksum'])] as $wrong) {
                try {
                    DB::transaction(fn () => app(DatabaseGrantAuthority::class)->assertActive($ctx, $grant['id'], $release, $wrong));
                    self::fail('Incorrect policy checksum was accepted.');
                } catch (LogicException) {
                    self::assertTrue(true);
                }
            }
            try {
                DB::transaction(fn () => DB::table('brands')->where('id', $ctx->brandId)->update(['active_knowledge_release_id' => null]));
                self::fail('Direct pointer update bypassed revocation.');
            } catch (QueryException) {
                self::assertTrue(true);
            }

            return [$release, $grant];
        });
        $editorCtx = new TenantContext($org->id, $brand->id, $editor->id, $editorMembership->id);
        app(TenantContextRunner::class)->run($editorCtx, function () use ($editorCtx, $release, $grant): void {
            foreach ([fn () => app(GrantRegistry::class)->issue($editorCtx, $release, []), fn () => app(GrantRegistry::class)->revoke($editorCtx, $grant['id'])] as $attempt) {
                try {
                    $attempt();
                    self::fail('Editor changed grant.');
                } catch (LogicException) {
                    self::assertTrue(true);
                }
            }
        });
        $otherCtx = new TenantContext($org->id, $other->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($otherCtx, function () use ($otherCtx, $release, $grant): void {
            try {
                DB::transaction(fn () => app(DatabaseGrantAuthority::class)->assertActive($otherCtx, $grant['id'], $release, $grant['policy_checksum']));
                self::fail('Cross-brand grant was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        try {
            app(DatabaseGrantAuthority::class)->assertActive($ctx, $grant['id'], $release, $grant['policy_checksum']);
            self::fail('Transaction is required.');
        } catch (LogicException) {
            self::assertTrue(true);
        }
    }

    public function test_invalid_fact_selection_cannot_replace_existing_grant(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($ctx, function () use ($ctx): void {
            [, $release, $fact] = $this->release($ctx);
            $registry = app(GrantRegistry::class);
            $grant = $registry->issue($ctx, $release, [$fact]);
            foreach ([[str_repeat('x', 26)], [$fact, $fact], [(string) Str::ulid()]] as $ids) {
                try {
                    $registry->issue($ctx, $release, $ids);
                    self::fail('Invalid facts were granted.');
                } catch (LogicException) {
                    self::assertNull(DB::table('vision_knowledge_grants')->where('id', $grant['id'])->value('revoked_at'));
                }
            }
            self::assertSame(1, DB::table('vision_knowledge_grants')->count());
        });
    }

    public function test_issuing_a_grant_does_not_create_ai_data_consent(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($ctx, function () use ($ctx): void {
            [, $release, $fact] = $this->release($ctx);
            DB::table('brands')->where('id', $ctx->brandId)->update(['settings' => '{}']);
            $grant = app(GrantRegistry::class)->issue($ctx, $release, [$fact]);
            $settings = json_decode(DB::table('brands')->where('id', $ctx->brandId)->value('settings'), true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('ai_data_policy', $settings);
            try {
                DB::transaction(fn () => app(DatabaseGrantAuthority::class)->assertActive($ctx, $grant['id'], $release, $grant['policy_checksum']));
                self::fail('Grant alone must not authorize transfer to AI.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
    }

    public function test_admin_can_manage_grants_but_viewer_and_unscoped_job_cannot(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        [$admin, $adminMember] = $this->member($org, 'admin', [$brand->id]);
        [$viewer, $viewerMember] = $this->member($org, 'viewer', [$brand->id]);
        $ownerCtx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        [$release, $fact] = app(TenantContextRunner::class)->run($ownerCtx, function () use ($ownerCtx): array {
            [, $release, $fact] = $this->release($ownerCtx);

            return [$release, $fact];
        });
        $adminCtx = new TenantContext($org->id, $brand->id, $admin->id, $adminMember->id);
        $grant = app(TenantContextRunner::class)->run($adminCtx, fn () => app(GrantRegistry::class)->issue($adminCtx, $release, [$fact]));
        $viewerCtx = new TenantContext($org->id, $brand->id, $viewer->id, $viewerMember->id);
        app(TenantContextRunner::class)->run($viewerCtx, function () use ($viewerCtx, $grant): void {
            try {
                app(GrantRegistry::class)->revoke($viewerCtx, $grant['id']);
                self::fail('Viewer revoked a grant.');
            } catch (LogicException) {
                self::assertNull(DB::table('vision_knowledge_grants')->where('id', $grant['id'])->value('revoked_at'));
            }
        });
        $jobCtx = new TenantContext($org->id, $brand->id, source: 'job');
        app(TenantContextRunner::class)->run($jobCtx, function () use ($jobCtx, $release): void {
            try {
                app(GrantRegistry::class)->issue($jobCtx, $release, []);
                self::fail('Unscoped job issued a grant.');
            } catch (LogicException) {
                self::assertSame(1, DB::table('vision_knowledge_grants')->whereNull('revoked_at')->count());
            }
        });
        app(TenantContextRunner::class)->run($adminCtx, fn () => self::assertTrue(app(GrantRegistry::class)->revoke($adminCtx, $grant['id'])));
    }

    public function test_gate_requires_active_membership_and_fails_closed_when_policy_is_absent(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($ctx, function () use ($ctx, $membership): void {
            [, $release, $fact] = $this->release($ctx);
            $grant = app(GrantRegistry::class)->issue($ctx, $release, [$fact]);
            DB::table('memberships')->where('id', $membership->id)->update(['status' => 'disabled']);
            try {
                DB::transaction(fn () => app(DatabaseGrantAuthority::class)->assertActive($ctx, $grant['id'], $release, $grant['policy_checksum']));
                self::fail('Disabled membership was accepted.');
            } catch (LogicException $error) {
                self::assertNull($error->getPrevious());
            }
            DB::table('memberships')->where('id', $membership->id)->update(['status' => 'active']);
            self::assertTrue(app(GrantRegistry::class)->revoke($ctx, $grant['id']));
            try {
                DB::transaction(fn () => app(DatabaseGrantAuthority::class)->assertActive($ctx, $grant['id'], $release, $grant['policy_checksum']));
                self::fail('Missing policy or revoked grant was accepted.');
            } catch (LogicException $error) {
                self::assertNull($error->getPrevious());
            }
        });
    }

    public function test_failed_release_audit_does_not_revoke_or_change_active_release(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($ctx, function () use ($ctx): void {
            [$draft, $release, $fact] = $this->release($ctx);
            $grant = app(GrantRegistry::class)->issue($ctx, $release, [$fact]);
            app()->instance(AuditWriter::class, new class implements AuditWriter
            {
                public function record(TenantContext $context, AuditEntry $entry): void
                {
                    throw new LogicException('Synthetic audit failure.');
                }
            });
            try {
                app(DatabaseKnowledgeRepository::class)->publish($ctx, $draft['id'], 2);
                self::fail('Release changed without audit.');
            } catch (LogicException) {
                self::assertNull(DB::table('vision_knowledge_grants')->where('id', $grant['id'])->value('revoked_at'));
                self::assertSame($release, DB::table('brands')->where('id', $ctx->brandId)->value('active_knowledge_release_id'));
                self::assertSame(1, DB::table('knowledge_releases')->where('status', 'published')->count());
            }
        });
    }

    public function test_audit_failure_rolls_back_issue_and_old_grant_revocation(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($ctx, function () use ($ctx): void {
            [, $release, $fact] = $this->release($ctx);
            $registry = app(GrantRegistry::class);
            $first = $registry->issue($ctx, $release, [$fact]);
            app()->instance(AuditWriter::class, new class implements AuditWriter
            {
                public function record(TenantContext $context, AuditEntry $entry): void
                {
                    throw new LogicException('Synthetic audit failure.');
                }
            });
            try {
                $registry->issue($ctx, $release, [$fact]);
                self::fail('Grant committed without audit.');
            } catch (LogicException) {
                self::assertNull(DB::table('vision_knowledge_grants')->where('id', $first['id'])->value('revoked_at'));
                self::assertSame(1, DB::table('vision_knowledge_grants')->count());
            }
        });
    }
}
