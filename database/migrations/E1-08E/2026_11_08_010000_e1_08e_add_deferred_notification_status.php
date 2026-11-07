<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_status_check');
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_status_check CHECK (status IN ('pending','deferred','sending','sent','failed','uncertain'))");
    }

    public function down(): void
    {
        DB::table('notification_deliveries')->where('status', 'deferred')->update(['status' => 'pending']);
        DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_status_check');
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_status_check CHECK (status IN ('pending','sending','sent','failed','uncertain'))");
    }
};
