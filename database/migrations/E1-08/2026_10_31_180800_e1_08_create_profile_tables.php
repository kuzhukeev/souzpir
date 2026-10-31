<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_preferences', function (Blueprint $t): void {
            $t->foreignUlid('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $t->string('timezone', 64)->nullable();
            $t->string('language', 8)->default('ru');
            $t->string('avatar_path', 255)->nullable();
            $t->timestampsTz();
        });
        Schema::create('profile_email_changes', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $t->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->ulid('brand_id');
            $t->text('target_email')->nullable();
            $t->string('target_fingerprint', 64)->nullable()->unique();
            $t->string('source_fingerprint', 64);
            $t->unsignedInteger('auth_version');
            $t->string('token_hash', 64)->nullable();
            $t->text('encrypted_token')->nullable();
            $t->string('status', 20);
            $t->timestampTz('expires_at');
            $t->timestampsTz();
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
        Schema::create('profile_telegram_accounts', function (Blueprint $t): void {
            $t->foreignUlid('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $t->string('chat_fingerprint', 64)->unique();
            $t->timestampsTz();
        });
        Schema::create('profile_telegram_links', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->ulid('brand_id');
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('token_hash', 64)->nullable()->unique();
            $t->timestampTz('expires_at')->nullable();
            $t->text('chat_id')->nullable();
            $t->text('pending_chat_id')->nullable();
            $t->string('pending_label', 120)->nullable();
            $t->string('chat_fingerprint', 64)->nullable();
            $t->timestampsTz();
            $t->unique(['brand_id', 'user_id']);
            $t->unique(['brand_id', 'chat_fingerprint']);
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
        Schema::create('profile_session_details', function (Blueprint $t): void {
            $t->string('session_hash', 64)->primary();
            $t->foreign('session_hash')->references('session_hash')->on('auth_session_registry')->cascadeOnDelete()->cascadeOnUpdate();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 500)->nullable();
            $t->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        foreach (['profile_session_details', 'profile_telegram_links', 'profile_telegram_accounts', 'profile_email_changes', 'profile_preferences'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
