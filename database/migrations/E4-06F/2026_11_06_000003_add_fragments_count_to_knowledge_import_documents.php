<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_knowledge_import_documents', function (Blueprint $table): void {
            $table->unsignedInteger('fragments_count')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('seller_knowledge_import_documents', function (Blueprint $table): void {
            $table->dropColumn('fragments_count');
        });
    }
};
