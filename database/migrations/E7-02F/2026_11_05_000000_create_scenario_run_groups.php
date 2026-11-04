<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_scenario_run_groups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->string('status');
            $table->json('items');
            $table->timestamps();
            $table->index(['organization_id', 'brand_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_scenario_run_groups');
    }
};
