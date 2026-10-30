<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('name');
            $t->string('status')->default('active');
            $t->foreignUlid('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->jsonb('settings')->default('{}');
            $t->timestampsTz();
        });
        Schema::create('brands', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('website', 2048)->nullable();
            $t->string('industry')->nullable();
            $t->string('market')->nullable();
            $t->string('language', 10)->default('ru');
            $t->string('timezone')->default('Europe/Moscow');
            $t->string('theme')->default('neutral');
            $t->string('status')->default('active');
            $t->jsonb('settings')->default('{}');
            $t->timestampsTz();
            $t->unique(['id', 'organization_id']);
        });
        Schema::create('memberships', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $t->string('role')->default('member');
            $t->string('status')->default('active');
            $t->jsonb('brand_ids')->default('[]');
            $t->jsonb('settings')->default('{}');
            $t->foreignUlid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['organization_id', 'user_id']);
        });
        Schema::create('invitations', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->string('email', 254);
            $t->string('role');
            $t->jsonb('brand_ids')->default('[]');
            $t->jsonb('settings')->default('{}');
            $t->char('token_hash', 64)->unique();
            $t->foreignUlid('invited_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('expires_at');
            $t->timestampTz('accepted_at')->nullable();
            $t->foreignUlid('accepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('revoked_at')->nullable();
            $t->timestampsTz();
            $t->index(['organization_id', 'email']);
        });
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_status_check CHECK (status IN ('active','disabled'))");
        DB::statement("ALTER TABLE brands ADD CONSTRAINT brands_status_check CHECK (status IN ('active','archived')), ADD CONSTRAINT brands_theme_check CHECK (theme IN ('confectionery','pyro','neutral'))");
        DB::statement("ALTER TABLE memberships ADD CONSTRAINT memberships_role_check CHECK (role IN ('owner','admin','member','viewer')), ADD CONSTRAINT memberships_status_check CHECK (status IN ('active','disabled'))");
        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_role_check CHECK (role IN ('admin','member','viewer')), ADD CONSTRAINT invitations_token_check CHECK (token_hash ~ '^[0-9a-f]{64}$'), ADD CONSTRAINT invitations_acceptance_check CHECK ((accepted_at IS NULL) = (accepted_by IS NULL)), ADD CONSTRAINT invitations_email_check CHECK (email = lower(btrim(email)))");
        foreach (['memberships', 'invitations'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_grants_check CHECK (jsonb_typeof(brand_ids) = 'array'), ADD CONSTRAINT {$table}_settings_check CHECK (jsonb_typeof(settings) = 'object' AND (NOT jsonb_exists(settings, 'sales_manager') OR (jsonb_typeof(settings->'sales_manager') = 'boolean' AND (settings->>'sales_manager' = 'false' OR role = 'member'))))");
        }
    }

    public function down(): void
    {
        foreach (['invitations', 'memberships', 'brands', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
