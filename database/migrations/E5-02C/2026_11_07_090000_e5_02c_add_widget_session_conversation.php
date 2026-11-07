<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_sessions', function (Blueprint $table): void {
            $table->ulid('conversation_id')->nullable();
            $table->ulid('contact_id')->nullable();
            $table->foreign(['conversation_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('conversations')->restrictOnDelete();
            $table->foreign(['contact_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('contacts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('widget_sessions', function (Blueprint $table): void {
            $table->dropForeign(['conversation_id', 'brand_id', 'organization_id']);
            $table->dropForeign(['contact_id', 'brand_id', 'organization_id']);
            $table->dropColumn(['conversation_id', 'contact_id']);
        });
    }
};
