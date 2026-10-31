<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_session_registry', function (Blueprint $table): void {
            $table->ulid('selected_organization_id')->nullable();
            $table->ulid('selected_brand_id')->nullable();
            $table->bigInteger('brand_revision')->default(0);
        });
    }

    public function down(): void
    {
        throw new LogicException('Automatic session brand selection rollback is unsupported; use the offline recovery procedure.');
    }
};
