<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protected_operation_key_guard', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->char('fingerprint', 64);
        });
        DB::statement("ALTER TABLE protected_operation_key_guard ADD CONSTRAINT c02_key_guard_singleton CHECK (id = 1 AND fingerprint ~ '^[0-9a-f]{64}$')");
        Schema::create('protected_operation_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->string('operation', 80);
            $table->char('key_digest', 64);
            $table->char('request_digest', 64);
            $table->text('result_ciphertext')->nullable();
            $table->timestampsTz();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->unique(['organization_id', 'brand_id', 'operation', 'key_digest'], 'c02_receipt_identity');
        });
        DB::statement("ALTER TABLE protected_operation_receipts ADD CONSTRAINT c02_receipt_digests CHECK (key_digest ~ '^[0-9a-f]{64}$' AND request_digest ~ '^[0-9a-f]{64}$')");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION c02_guard_receipt_history() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'UPDATE' THEN
                    RAISE EXCEPTION 'Idempotency history cannot be removed';
                END IF;
                IF OLD.result_ciphertext IS NOT NULL OR NEW.result_ciphertext IS NULL
                    OR NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.brand_id IS DISTINCT FROM OLD.brand_id
                    OR NEW.operation IS DISTINCT FROM OLD.operation
                    OR NEW.key_digest IS DISTINCT FROM OLD.key_digest
                    OR NEW.request_digest IS DISTINCT FROM OLD.request_digest
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Idempotency receipt is immutable';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER c02_guard_receipt_history BEFORE UPDATE OR DELETE ON protected_operation_receipts
                FOR EACH ROW EXECUTE FUNCTION c02_guard_receipt_history();
            CREATE TRIGGER c02_guard_receipt_truncate BEFORE TRUNCATE ON protected_operation_receipts
                FOR EACH STATEMENT EXECUTE FUNCTION c02_guard_receipt_history();

            CREATE OR REPLACE FUNCTION c02_require_complete_receipt() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM protected_operation_receipts WHERE id = NEW.id AND result_ciphertext IS NULL) THEN
                    RAISE EXCEPTION 'Incomplete idempotency receipt cannot commit';
                END IF;
                RETURN NULL;
            END;
            $$;
            CREATE CONSTRAINT TRIGGER c02_require_complete_receipt
                AFTER INSERT OR UPDATE ON protected_operation_receipts DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION c02_require_complete_receipt();

            CREATE OR REPLACE FUNCTION c02_immutable_key_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Idempotency key guard cannot be changed';
            END;
            $$;
            CREATE TRIGGER c02_immutable_key_guard BEFORE UPDATE OR DELETE ON protected_operation_key_guard
                FOR EACH ROW EXECUTE FUNCTION c02_immutable_key_guard();
            CREATE TRIGGER c02_key_guard_truncate BEFORE TRUNCATE ON protected_operation_key_guard
                FOR EACH STATEMENT EXECUTE FUNCTION c02_immutable_key_guard();
            SQL);
    }

    public function down(): void
    {
        if (DB::table('protected_operation_receipts')->exists()) {
            throw new LogicException('Cannot discard idempotency history.');
        }
        Schema::dropIfExists('protected_operation_receipts');
        Schema::dropIfExists('protected_operation_key_guard');
        DB::unprepared('DROP FUNCTION IF EXISTS c02_guard_receipt_history(); DROP FUNCTION IF EXISTS c02_require_complete_receipt(); DROP FUNCTION IF EXISTS c02_immutable_key_guard();');
    }
};
