<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_delivery_intents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('email_fingerprint', 64);
            $table->unsignedInteger('auth_version');
            $table->string('status', 16);
            $table->string('claim_hash', 64)->nullable();
            $table->string('reason', 32)->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at', 'id']);
        });
        DB::statement("ALTER TABLE password_delivery_intents ADD CONSTRAINT password_delivery_status_check CHECK (status IN ('pending','sending','transmitting','sent','failed','cancelled','uncertain'))");
        DB::statement("CREATE UNIQUE INDEX password_delivery_one_open ON password_delivery_intents (user_id) WHERE status IN ('pending','sending','transmitting')");
    }

    public function down(): void
    {
        Schema::dropIfExists('password_delivery_intents');
    }
};
