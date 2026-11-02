<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_contact_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->ulid('brand_id');
            $table->ulid('widget_id');
            $table->string('origin');
            $table->string('name', 100)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('consent_url', 2048);
            $table->timestampTz('consented_at');
            $table->timestampsTz();
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->foreign('widget_id')->references('id')->on('widget_settings')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_contact_requests');
    }
};
