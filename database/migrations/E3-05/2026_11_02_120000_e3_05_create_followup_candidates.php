<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_followup_candidates', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->ulid('conversation_id');
            $t->string('reason', 32);
            $t->timestampTz('source_at');
            $t->unsignedInteger('sequence');
            $t->text('text');
            $t->string('status', 20)->default('pending');
            $t->timestampsTz();
            $t->unique(['conversation_id', 'reason', 'source_at', 'sequence'], 'seller_followup_candidate_unique');
            $t->foreign(['conversation_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('conversations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_followup_candidates');
    }
};
