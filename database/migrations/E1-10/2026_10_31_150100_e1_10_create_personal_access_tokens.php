<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tokenable_type');
            $table->ulid('tokenable_id');
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->string('name', 60);
            $table->string('token', 64)->unique();
            $table->string('token_last_four', 4);
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();
            $table->index(['tokenable_type', 'tokenable_id']);
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE personal_access_tokens ADD CONSTRAINT e110_token_brand_matches CHECK (tokenable_id = brand_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
