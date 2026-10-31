<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SINGLE = ['kn_persona', 'kn_policy', 'kn_conditions', 'kn_guard_rules', 'kn_escalation', 'kn_fewshot'];

    private const LIST = ['kn_intents', 'kn_triggers', 'kn_closed_topics'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Knowledge releases require PostgreSQL.');
        }
        Schema::create('knowledge_releases', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->ulid('brand_id');
            $t->ulid('parent_id')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 20)->default('draft');
            $t->string('checksum', 64)->nullable();
            $t->timestampTz('published_at')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $t->unique(['id', 'brand_id', 'organization_id']);
            $t->foreign(['parent_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('knowledge_releases')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE knowledge_releases ADD CONSTRAINT knowledge_release_status_check CHECK ((status IN ('draft','building') AND published_at IS NULL AND checksum IS NULL) OR (status = 'published' AND published_at IS NOT NULL AND checksum IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX knowledge_one_draft_per_brand ON knowledge_releases (brand_id) WHERE status = 'draft'");
        foreach (self::SINGLE as $name) {
            Schema::create($name, function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->foreignUlid('release_id')->constrained('knowledge_releases')->restrictOnDelete();
                $t->jsonb('data');
                $t->unique('release_id');
            });
        }
        foreach (self::LIST as $name) {
            Schema::create($name, function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->foreignUlid('release_id')->constrained('knowledge_releases')->restrictOnDelete();
                $t->string('key');
                $t->unsignedInteger('position');
                $t->jsonb('data');
                $t->unique(['release_id', 'key']);
                $t->unique(['release_id', 'position']);
            });
        }
        Schema::create('kn_fact_groups', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('release_id')->constrained('knowledge_releases')->restrictOnDelete();
            $t->string('key');
            $t->unsignedInteger('position');
            $t->jsonb('data');
            $t->unique(['release_id', 'key']);
            $t->unique(['release_id', 'position']);
        });
        Schema::create('kn_facts', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('logical_id');
            $t->foreignUlid('group_id')->constrained('kn_fact_groups')->restrictOnDelete();
            $t->text('text');
            $t->unsignedInteger('position');
            $t->string('source', 20)->default('manual');
            $t->string('status', 20)->default('active');
            $t->unique(['group_id', 'position']);
            $t->unique(['group_id', 'logical_id']);
        });
        DB::statement("ALTER TABLE kn_facts ADD CONSTRAINT kn_facts_source_status CHECK (source IN ('manual','import','url') AND status IN ('active','inactive'))");
        Schema::table('brands', function (Blueprint $t): void {
            $t->ulid('active_knowledge_release_id')->nullable();
        });
        DB::statement('ALTER TABLE brands ADD CONSTRAINT brands_active_knowledge_fk FOREIGN KEY (active_knowledge_release_id,id,organization_id) REFERENCES knowledge_releases(id,brand_id,organization_id)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION knowledge_release_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.status = 'published' THEN RAISE EXCEPTION 'Published knowledge releases are immutable'; END IF;
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER knowledge_release_immutable_trigger BEFORE UPDATE OR DELETE ON knowledge_releases FOR EACH ROW EXECUTE FUNCTION knowledge_release_immutable();
CREATE OR REPLACE FUNCTION knowledge_child_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE old_release char(26); new_release char(26); old_group char(26); new_group char(26); locked_status text; locked_group record;
BEGIN
  IF TG_TABLE_NAME = 'kn_facts' THEN
    IF TG_OP <> 'INSERT' THEN old_group := OLD.group_id; END IF;
    IF TG_OP <> 'DELETE' THEN new_group := NEW.group_id; END IF;
    FOR locked_group IN SELECT id, release_id FROM kn_fact_groups WHERE id IN (old_group,new_group) ORDER BY id FOR SHARE LOOP
      IF locked_group.id = old_group THEN old_release := locked_group.release_id; END IF;
      IF locked_group.id = new_group THEN new_release := locked_group.release_id; END IF;
    END LOOP;
  ELSE
    IF TG_OP <> 'INSERT' THEN old_release := OLD.release_id; END IF;
    IF TG_OP <> 'DELETE' THEN new_release := NEW.release_id; END IF;
  END IF;
  FOR locked_status IN SELECT status FROM knowledge_releases WHERE id IN (old_release,new_release) ORDER BY id FOR NO KEY UPDATE LOOP
    IF locked_status = 'published' THEN RAISE EXCEPTION 'Published knowledge content is immutable'; END IF;
  END LOOP;
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE OR REPLACE FUNCTION knowledge_no_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Knowledge tables cannot be truncated'; END $$;
CREATE OR REPLACE FUNCTION knowledge_active_published() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.active_knowledge_release_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM knowledge_releases WHERE id = NEW.active_knowledge_release_id AND brand_id = NEW.id AND organization_id = NEW.organization_id AND status = 'published'
  ) THEN RAISE EXCEPTION 'Active knowledge release must be published and belong to the brand'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER knowledge_active_published_trigger BEFORE INSERT OR UPDATE OF active_knowledge_release_id ON brands FOR EACH ROW EXECUTE FUNCTION knowledge_active_published();
SQL);
        foreach ([...self::SINGLE, ...self::LIST, 'kn_fact_groups', 'kn_facts'] as $table) {
            DB::statement("CREATE TRIGGER knowledge_child_immutable_trigger BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION knowledge_child_immutable()");
        }
        foreach (['knowledge_releases', ...self::SINGLE, ...self::LIST, 'kn_fact_groups', 'kn_facts'] as $table) {
            DB::statement("CREATE TRIGGER knowledge_no_truncate_trigger BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION knowledge_no_truncate()");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS knowledge_active_published_trigger ON brands');
        DB::statement('ALTER TABLE brands DROP CONSTRAINT IF EXISTS brands_active_knowledge_fk');
        Schema::table('brands', fn (Blueprint $t) => $t->dropColumn('active_knowledge_release_id'));
        foreach (['kn_facts', 'kn_fact_groups', ...self::LIST, ...self::SINGLE] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('knowledge_releases');
        DB::statement('DROP FUNCTION IF EXISTS knowledge_active_published()');
        DB::statement('DROP FUNCTION IF EXISTS knowledge_no_truncate()');
        DB::statement('DROP FUNCTION IF EXISTS knowledge_child_immutable()');
        DB::statement('DROP FUNCTION IF EXISTS knowledge_release_immutable()');
    }
};
