<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $t->string('type', 24)->default('support');
            $t->string('subject', 50);
            $t->string('module', 50);
            $t->text('body');
            $t->string('status', 24)->default('open');
            $t->string('delivery_status', 24)->default('pending');
            $t->timestampsTz();
            $t->unique(['id', 'organization_id', 'user_id']);
            $t->foreign(['organization_id', 'user_id'])->references(['organization_id', 'user_id'])->on('memberships')->restrictOnDelete();
            $t->index(['organization_id', 'user_id', 'created_at']);
        });
        Schema::create('ticket_attachments', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('user_id');
            $t->ulid('ticket_id');
            $t->string('name', 180);
            $t->string('mime_type', 120);
            $t->unsignedBigInteger('size_bytes');
            $t->string('storage_path', 300)->unique();
            $t->char('sha256', 64);
            $t->timestampsTz();
            $t->foreign(['ticket_id', 'organization_id', 'user_id'])->references(['id', 'organization_id', 'user_id'])->on('tickets')->restrictOnDelete();
        });
        Schema::create('support_access_grants', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('ticket_id')->unique();
            $t->ulid('granted_by');
            $t->timestampTz('granted_at');
            $t->timestampTz('expires_at');
            $t->timestampTz('revoked_at')->nullable();
            $t->timestampsTz();
            $t->foreign(['ticket_id', 'organization_id', 'granted_by'])->references(['id', 'organization_id', 'user_id'])->on('tickets')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT e112_ticket_status CHECK (status IN ('open','closed')), ADD CONSTRAINT e112_delivery_status CHECK (delivery_status IN ('pending','sending','not_configured','disabled','sent','uncertain'))");
        DB::statement("ALTER TABLE support_access_grants ADD CONSTRAINT e112_grant_ttl CHECK (expires_at = granted_at + interval '72 hours')");
    }

    public function down(): void
    {
        Schema::dropIfExists('support_access_grants');
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('tickets');
    }
};
