<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vision_schedule_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('organization_id', 26);
            $table->char('brand_id', 26);
            $table->timestampTz('effective_at');
            $table->json('schedule');
            $table->index(['organization_id', 'brand_id', 'effective_at']);
        });
        foreach (DB::table('monitoring_schedules')->get() as $row) {
            DB::table('vision_schedule_versions')->insert(['id' => (string) Str::ulid(), 'organization_id' => $row->organization_id, 'brand_id' => $row->brand_id, 'effective_at' => now(), 'schedule' => json_encode(['enabled' => (bool) $row->enabled, 'frequency' => $row->frequency, 'weekday' => $row->weekday, 'local_time' => $row->local_time, 'timezone' => $row->timezone, 'models' => $row->models ? json_decode($row->models, true, 32, JSON_THROW_ON_ERROR) : [], 'next_run_at' => $row->next_run_at], JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vision_schedule_versions');
    }
};
