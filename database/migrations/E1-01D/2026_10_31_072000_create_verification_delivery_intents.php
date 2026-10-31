<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_delivery_intents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('session_hash', 64);
            $table->unsignedInteger('auth_version');
            $table->string('email_fingerprint', 64);
            $table->string('status', 16);
            $table->string('claim_hash', 64)->nullable();
            $table->string('reason', 32)->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'status']);
        });
        DB::statement("ALTER TABLE verification_delivery_intents ADD CONSTRAINT verification_delivery_status_check CHECK (status IN ('pending','sending','transmitting','sent','failed','cancelled','uncertain'))");
        DB::statement("CREATE UNIQUE INDEX verification_delivery_one_open ON verification_delivery_intents (user_id) WHERE status IN ('pending','sending','transmitting','uncertain')");
        Schema::create('verification_delivery_keys', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('key_hash', 64);
            $table->string('session_hash', 64);
            $table->foreignUlid('intent_id')->constrained('verification_delivery_intents')->restrictOnDelete();
            $table->timestampsTz();
            $table->primary(['user_id', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_delivery_keys');
        Schema::dropIfExists('verification_delivery_intents');
    }
};
