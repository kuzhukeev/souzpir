<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vision_knowledge_grants', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->ulid('release_id');
            $t->string('purpose', 32);
            $t->string('data_class', 32);
            $t->char('policy_checksum', 64);
            $t->foreignUlid('issued_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->timestampTz('revoked_at')->nullable();
            $t->foreignUlid('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('revocation_reason', 32)->nullable();
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $t->foreign(['release_id', 'brand_id', 'organization_id'], 'vision_grants_release_tenant_fk')
                ->references(['id', 'brand_id', 'organization_id'])->on('knowledge_releases')->restrictOnDelete();
            $t->index(['organization_id', 'brand_id', 'revoked_at'], 'vision_grants_brand_active_idx');
        });
        DB::statement("ALTER TABLE vision_knowledge_grants ADD CONSTRAINT vision_grants_purpose_check CHECK (purpose = 'vision' AND data_class = 'knowledge_public')");
        DB::statement("ALTER TABLE vision_knowledge_grants ADD CONSTRAINT vision_grants_checksum_check CHECK (policy_checksum ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE vision_knowledge_grants ADD CONSTRAINT vision_grants_revocation_check CHECK ((revoked_at IS NULL AND revoked_by IS NULL AND revocation_reason IS NULL) OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND revocation_reason IN ('manual', 'replaced', 'release_changed')))");
        DB::statement('CREATE UNIQUE INDEX vision_grants_one_active ON vision_knowledge_grants (organization_id, brand_id) WHERE revoked_at IS NULL');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION vision_grant_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Vision grants cannot be deleted';
    END IF;
    IF OLD.revoked_at IS NOT NULL OR NEW.revoked_at IS NULL
       OR (to_jsonb(NEW) - 'revoked_at' - 'revoked_by' - 'revocation_reason')
          IS DISTINCT FROM (to_jsonb(OLD) - 'revoked_at' - 'revoked_by' - 'revocation_reason') THEN
        RAISE EXCEPTION 'Vision grants are append-only and revocation is irreversible';
    END IF;
    RETURN NEW;
END
$$;
CREATE TRIGGER vision_grant_update_guard BEFORE UPDATE OR DELETE ON vision_knowledge_grants
FOR EACH ROW EXECUTE FUNCTION vision_grant_guard();
CREATE OR REPLACE FUNCTION vision_grant_no_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Vision grants cannot be truncated'; END
$$;
CREATE TRIGGER vision_grant_truncate_guard BEFORE TRUNCATE ON vision_knowledge_grants
FOR EACH STATEMENT EXECUTE FUNCTION vision_grant_no_truncate();
CREATE OR REPLACE FUNCTION vision_release_change_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.active_knowledge_release_id IS DISTINCT FROM OLD.active_knowledge_release_id
       AND EXISTS (SELECT 1 FROM vision_knowledge_grants g WHERE g.organization_id = OLD.organization_id AND g.brand_id = OLD.id AND g.revoked_at IS NULL) THEN
        RAISE EXCEPTION 'Active knowledge grants must be revoked before release changes';
    END IF;
    RETURN NEW;
END
$$;
CREATE TRIGGER vision_release_change_guard BEFORE UPDATE OF active_knowledge_release_id ON brands
FOR EACH ROW EXECUTE FUNCTION vision_release_change_guard();
CREATE OR REPLACE FUNCTION vision_policy_change_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF (NEW.settings->'vision_knowledge' IS DISTINCT FROM OLD.settings->'vision_knowledge'
        OR NEW.settings->'ai_data_policy'->'knowledge_public' IS DISTINCT FROM OLD.settings->'ai_data_policy'->'knowledge_public')
       AND EXISTS (SELECT 1 FROM vision_knowledge_grants g WHERE g.organization_id = OLD.organization_id AND g.brand_id = OLD.id AND g.revoked_at IS NULL) THEN
        RAISE EXCEPTION 'Active knowledge grants must be revoked before policy changes';
    END IF;
    RETURN NEW;
END
$$;
CREATE TRIGGER vision_policy_change_guard BEFORE UPDATE OF settings ON brands
FOR EACH ROW EXECUTE FUNCTION vision_policy_change_guard();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS vision_policy_change_guard ON brands');
        DB::statement('DROP FUNCTION IF EXISTS vision_policy_change_guard()');
        DB::statement('DROP TRIGGER IF EXISTS vision_release_change_guard ON brands');
        DB::statement('DROP FUNCTION IF EXISTS vision_release_change_guard()');
        Schema::dropIfExists('vision_knowledge_grants');
        DB::statement('DROP FUNCTION IF EXISTS vision_grant_guard()');
        DB::statement('DROP FUNCTION IF EXISTS vision_grant_no_truncate()');
    }
};
