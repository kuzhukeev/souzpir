<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table): void {
            $table->unique(['id', 'organization_id'], 'invitation_issuance_id_organization_unique');
        });
        Schema::create('invitation_issuance_attempts', function (Blueprint $table): void {
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->char('attempt_hash', 64);
            $table->ulid('invitation_id');
            $table->timestampTz('created_at');
            $table->primary(['organization_id', 'actor_user_id', 'attempt_hash'], 'invitation_issuance_attempts_pk');
            $table->unique('invitation_id');
            $table->foreign(['organization_id', 'actor_user_id'], 'invitation_issuance_actor_membership_fk')
                ->references(['organization_id', 'user_id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['invitation_id', 'organization_id'], 'invitation_issuance_invitation_org_fk')
                ->references(['id', 'organization_id'])->on('invitations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_issuance_attempts');
        Schema::table('invitations', function (Blueprint $table): void {
            $table->dropUnique('invitation_issuance_id_organization_unique');
        });
    }
};
