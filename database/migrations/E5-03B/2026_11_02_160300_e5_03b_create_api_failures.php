<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_api_request_failures', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->nullable();
            $table->ulid('brand_id')->nullable();
            $table->string('endpoint', 16);
            $table->unsignedSmallInteger('http_status');
            $table->string('category', 24);
            $table->timestampTz('created_at');
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->index(['organization_id', 'brand_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_api_request_failures');
    }
};
