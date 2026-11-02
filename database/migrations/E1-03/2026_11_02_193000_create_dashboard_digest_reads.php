<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_digest_reads', function (Blueprint $table): void {
            $table->foreignUlid('batch_id')->primary()->constrained('seller_digest_batches')->restrictOnDelete();
            $table->timestampTz('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_digest_reads');
    }
};
