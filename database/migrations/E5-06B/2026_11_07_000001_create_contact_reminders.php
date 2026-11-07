<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_contact_reminder_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->boolean('enabled')->default(false);
            $table->timestampsTz();
            $table->unique(['organization_id', 'brand_id']);
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
        Schema::create('seller_contact_reminder_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('conversation_id');
            $table->ulid('user_id');
            $table->timestampTz('due_at');
            $table->string('status', 24)->default('pending');
            $table->timestampsTz();
            $table->unique(['brand_id', 'conversation_id', 'user_id', 'due_at'], 'seller_contact_reminder_once');
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->foreign(['conversation_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('conversations')->restrictOnDelete();
            $table->foreign(['organization_id', 'user_id'])->references(['organization_id', 'user_id'])->on('memberships')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_contact_reminder_events');
        Schema::dropIfExists('seller_contact_reminder_settings');
    }
};
