<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_domains', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->string('host', 253);
            $table->string('purpose', 32);
            $table->string('verification_method', 8);
            $table->string('status', 16)->default('pending');
            $table->string('verification_token', 96);
            $table->unsignedInteger('check_version')->default(0);
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'brand_id', 'host']);
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE brand_domains ADD CONSTRAINT e105_domain_status CHECK (status IN ('pending', 'verified', 'failed'))");
        DB::statement("ALTER TABLE brand_domains ADD CONSTRAINT e105_domain_method CHECK (verification_method IN ('file', 'dns'))");
        DB::statement("ALTER TABLE brand_domains ADD CONSTRAINT e105_domain_purpose CHECK (purpose IN ('widget', 'widget_and_visibility'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_domains');
    }
};
