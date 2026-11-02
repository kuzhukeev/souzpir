<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_distinct_contact_pairs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('first_contact_id');
            $table->ulid('second_contact_id');
            $table->timestampsTz();
            $table->unique(['brand_id', 'first_contact_id', 'second_contact_id']);
            $table->foreign(['first_contact_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('contacts')->restrictOnDelete();
            $table->foreign(['second_contact_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('contacts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_distinct_contact_pairs');
    }
};
