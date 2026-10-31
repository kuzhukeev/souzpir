<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_outbox_cursors', function (Blueprint $table): void {
            $table->string('name', 100)->primary();
            $table->timestampTz('last_created_at')->nullable();
            $table->ulid('last_event_id')->nullable();
            $table->timestampTz('updated_at');
        });
        DB::statement('ALTER TABLE notification_outbox_cursors ADD CONSTRAINT notification_cursor_pair CHECK ((last_created_at IS NULL) = (last_event_id IS NULL))');
        DB::statement('CREATE INDEX outbox_pending_cursor_idx ON outbox_events (created_at, id) WHERE processed_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS outbox_pending_cursor_idx');
        Schema::dropIfExists('notification_outbox_cursors');
    }
};
