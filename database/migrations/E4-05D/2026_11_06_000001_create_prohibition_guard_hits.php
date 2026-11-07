<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prohibition_guard_hits', function (Blueprint $table): void {
            $table->id();
            $table->char('organization_id', 26);
            $table->char('brand_id', 26);
            $table->char('responder_id', 26);
            $table->string('rule_id', 100);
            $table->timestampTz('created_at');
            $table->unique(['responder_id', 'rule_id']);
            $table->index(['organization_id', 'brand_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prohibition_guard_hits');
    }
};
