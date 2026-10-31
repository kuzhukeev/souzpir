<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_test_notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->ulid('brand_id');
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->unsignedInteger('auth_version');
            $table->string('channel', 20);
            $table->string('status', 20);
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->unique(['brand_id', 'user_id', 'request_key'], 'profile_notification_test_request_unique');
        });
        DB::statement("ALTER TABLE profile_test_notifications ADD CONSTRAINT profile_test_channel_check CHECK (channel IN ('cabinet', 'email', 'telegram')), ADD CONSTRAINT profile_test_status_check CHECK (status IN ('pending', 'sending', 'sent', 'uncertain', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_test_notifications');
    }
};
