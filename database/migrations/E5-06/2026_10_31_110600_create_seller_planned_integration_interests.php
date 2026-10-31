<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_planned_integration_interests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('integration', 40);
            $table->timestampsTz();
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->foreign(['organization_id', 'user_id'])->references(['organization_id', 'user_id'])->on('memberships')->restrictOnDelete();
            $table->unique(['organization_id', 'brand_id', 'user_id', 'integration'], 'seller_planned_interest_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_planned_integration_interests');
    }
};
