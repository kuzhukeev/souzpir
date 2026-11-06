<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (config('billing.example_plans', []) as $code => $plan) {
            $existingPlan = DB::table('plans')->where('code', $code)->first(['id', 'created_at']);
            $id = $existingPlan?->id ?? (string) Str::ulid();
            DB::table('plans')->updateOrInsert(['code' => $code], ['id' => $id, 'name' => $plan['name'], 'is_public' => true, 'updated_at' => now(), 'created_at' => $existingPlan?->created_at ?? now()]);
            foreach ($plan['limits'] as $key => $value) {
                $existing = DB::table('plan_limits')->where(['plan_id' => $id, 'key' => $key])->first(['id', 'created_at']);
                DB::table('plan_limits')->updateOrInsert(['plan_id' => $id, 'key' => $key], ['id' => $existing?->id ?? (string) Str::ulid(), 'value' => $value, 'unit' => '', 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Plan assignments may already reference these rows; keep data on rollback.
    }
};
