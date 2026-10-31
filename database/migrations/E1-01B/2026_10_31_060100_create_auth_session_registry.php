<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_session_registry', function (Blueprint $table): void {
            $table->string('session_hash', 64)->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('auth_version');
            $table->string('remember_fingerprint', 64)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_session_registry');
    }
};
