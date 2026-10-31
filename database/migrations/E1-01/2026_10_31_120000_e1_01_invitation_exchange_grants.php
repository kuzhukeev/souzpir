<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table): void {
            $table->timestampTz('exchanged_at')->nullable();
        });
        Schema::create('invitation_exchange_sessions', function (Blueprint $table): void {
            $table->char('session_hash', 64)->primary();
            $table->char('document_epoch', 64);
            $table->unsignedBigInteger('sequence')->default(0);
            $table->foreignUlid('invitation_id')->nullable()->constrained('invitations')->restrictOnDelete();
            $table->timestampTz('grant_expires_at')->nullable();
            $table->timestampsTz();
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_exchange_sessions');
        // Rolling back must not make already exchanged bearer links usable again.
        DB::table('invitations')->whereNotNull('exchanged_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
        Schema::table('invitations', fn (Blueprint $table) => $table->dropColumn('exchanged_at'));
    }
};
