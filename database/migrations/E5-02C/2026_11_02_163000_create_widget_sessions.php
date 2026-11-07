<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('widget_id');
            $table->string('origin');
            $table->string('token_hash', 64)->unique();
            $table->ulid('contact_request_id')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->foreign('widget_id')->references('id')->on('widget_settings')->restrictOnDelete();
            $table->foreign('contact_request_id')->references('id')->on('widget_contact_requests')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_sessions');
    }
};
