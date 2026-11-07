<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e306g_job_turn_claims', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('conversation_id');
            $table->foreignUlid('turn_id')->unique()->constrained('seller_turns')->restrictOnDelete();
            $table->string('claim_hash', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['conversation_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('conversations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e306g_job_turn_claims');
    }
};
