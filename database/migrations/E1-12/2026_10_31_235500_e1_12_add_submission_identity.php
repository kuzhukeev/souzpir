<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            // Older tickets have no submission identity and remain readable.
            $table->ulid('submission_key')->nullable();
            $table->char('submission_hash', 64)->nullable();
            $table->unique(['organization_id', 'user_id', 'submission_key'], 'e112_submission_owner_unique');
        });
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT e112_submission_pair CHECK ((submission_key IS NULL AND submission_hash IS NULL) OR (submission_key IS NOT NULL AND submission_hash IS NOT NULL AND submission_hash ~ '^[0-9a-f]{64}$'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tickets DROP CONSTRAINT e112_submission_pair');
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropUnique('e112_submission_owner_unique');
            $table->dropColumn(['submission_key', 'submission_hash']);
        });
    }
};
