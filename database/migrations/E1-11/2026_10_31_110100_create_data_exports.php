<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('user_id');
            $table->string('module', 80);
            $table->date('period_from');
            $table->date('period_to');
            $table->string('path');
            $table->char('sha256', 64);
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->index(['organization_id', 'brand_id', 'user_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('data_exports'); }
};
