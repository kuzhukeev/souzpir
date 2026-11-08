<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_tool_call_journal', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->ulid('dialog_id')->nullable();
            $t->ulid('turn_id')->nullable();
            $t->string('tool', 32);
            $t->string('status', 32);
            $t->timestampTz('created_at');
            $t->unique(['organization_id', 'brand_id', 'dialog_id', 'turn_id', 'tool']);
            $t->index(['organization_id', 'brand_id', 'tool', 'created_at']);
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $t->foreign(['dialog_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('conversations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_tool_call_journal');
    }
};
