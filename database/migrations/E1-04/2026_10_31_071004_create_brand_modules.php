<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_modules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->string('module', 20);
            $table->boolean('enabled')->default(false);
            $table->timestampsTz();
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->unique(['brand_id', 'module']);
        });
        DB::statement("ALTER TABLE brand_modules ADD CONSTRAINT e104_module_check CHECK (module IN ('seller', 'vision'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_modules');
    }
};
