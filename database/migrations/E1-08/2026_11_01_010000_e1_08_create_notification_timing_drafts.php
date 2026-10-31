<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_notification_timing_drafts', function (Blueprint $table): void {
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->ulid('brand_id');
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('quiet_start', 5)->nullable();
            $table->string('quiet_end', 5)->nullable();
            $table->string('digest_time', 5)->nullable();
            $table->timestampsTz();
            $table->primary(['organization_id', 'brand_id', 'user_id']);
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_notification_timing_drafts');
    }
};
