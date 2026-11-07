<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vision_measurement_subscriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('brand_id')->constrained('brands');
            $table->foreignUlid('user_id')->constrained('users');
            $table->foreignUlid('measurement_id')->constrained('measurements');
            $table->timestampTz('expires_at');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'brand_id', 'user_id', 'measurement_id'], 'vision_measurement_subscriptions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vision_measurement_subscriptions');
    }
};
