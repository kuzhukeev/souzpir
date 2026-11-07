<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_followup_candidates', function (Blueprint $table): void {
            $table->timestampTz('scheduled_at')->nullable();
            $table->boolean('bulk')->default(false);
        });
        Schema::create('seller_followup_consents', function (Blueprint $table): void {
            $table->bigIncrements('sequence');
            $table->ulid('id')->unique();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('conversation_id');
            $table->string('event', 16);
            $table->string('source', 16);
            $table->ulid('message_id')->nullable();
            $table->ulid('actor_id')->nullable();
            $table->text('basis');
            $table->timestampsTz();
            $table->foreign(['conversation_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('conversations')->restrictOnDelete();
            $table->index(['organization_id', 'brand_id', 'conversation_id', 'created_at'], 'followup_consent_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_followup_consents');
        Schema::table('seller_followup_candidates', function (Blueprint $table): void {
            $table->dropColumn(['scheduled_at', 'bulk']);
        });
    }
};
