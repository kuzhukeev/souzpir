<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_promise_notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('task_id');
            $table->timestampTz('due_at');
            $table->timestampTz('created_at');
            $table->unique(['task_id', 'due_at']);
            $table->foreign('task_id')->references('id')->on('seller_tasks')->restrictOnDelete();
        });
        Schema::create('seller_customer_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('contact_id');
            $table->ulid('author_id');
            $table->text('body');
            $table->timestampsTz();
            $table->foreign(['contact_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('contacts')->restrictOnDelete();
            $table->foreign(['organization_id', 'author_id'])->references(['organization_id', 'user_id'])->on('memberships')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_customer_notes');
        Schema::dropIfExists('seller_promise_notifications');
    }
};
