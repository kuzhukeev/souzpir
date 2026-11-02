<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_contact_imports', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->ulid('user_id');
            $t->string('filename', 200);
            $t->text('data_encrypted');
            $t->string('status', 20)->default('preview');
            $t->timestampsTz();
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $t->foreign(['organization_id', 'user_id'])->references(['organization_id', 'user_id'])->on('memberships')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_contact_imports');
    }
};
