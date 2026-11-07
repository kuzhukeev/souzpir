<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Separate from `messages`/`history_revision`: the c02 history trigger bumps
     * `conversations.history_revision` on any `messages` row update, which would
     * invalidate ready-suggestion/turn staleness checks. Tracking "read" on the
     * conversation itself (outside the trigger's watched column list) avoids that.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->unsignedBigInteger('read_sequence')->default(0);
            $table->timestampTz('read_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn(['read_sequence', 'read_at']);
        });
    }
};
