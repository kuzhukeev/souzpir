<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- Lease identities are permanently reserved, including after session pruning.
CREATE TABLE c02_session_lease_ids (id char(26) PRIMARY KEY);
CREATE OR REPLACE FUNCTION c02_session_lease_id_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Session lease identities cannot be rewritten'; END;
$$;
CREATE TRIGGER c02_session_lease_id_guard BEFORE UPDATE OR DELETE ON c02_session_lease_ids
  FOR EACH ROW EXECUTE FUNCTION c02_session_lease_id_guard();
CREATE TRIGGER c02_session_lease_id_no_truncate BEFORE TRUNCATE ON c02_session_lease_ids
  FOR EACH STATEMENT EXECUTE FUNCTION c02_session_lease_id_guard();

CREATE TABLE c02_session_leases (
    id char(26) PRIMARY KEY REFERENCES c02_session_lease_ids(id),
    session_hash varchar(64) NOT NULL UNIQUE REFERENCES auth_session_registry(session_hash)
      ON UPDATE CASCADE ON DELETE CASCADE,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE OR REPLACE FUNCTION c02_session_lease_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        INSERT INTO c02_session_lease_ids(id) VALUES (NEW.id);
        RETURN NEW;
    END IF;
    IF TG_OP = 'UPDATE' AND pg_trigger_depth() > 1 AND NEW.id = OLD.id
       AND NEW.created_at = OLD.created_at THEN RETURN NEW; END IF;
    IF TG_OP = 'DELETE' AND pg_trigger_depth() > 1 THEN RETURN OLD; END IF;
    RAISE EXCEPTION 'Session lease identity cannot be modified directly';
END;
$$;
CREATE TRIGGER c02_session_lease_guard BEFORE INSERT OR UPDATE OR DELETE ON c02_session_leases
  FOR EACH ROW EXECUTE FUNCTION c02_session_lease_guard();
CREATE TRIGGER c02_session_lease_no_truncate BEFORE TRUNCATE ON c02_session_leases
  FOR EACH STATEMENT EXECUTE FUNCTION c02_session_lease_guard();

-- Historical receipts intentionally outlive retention of the session lease.
-- A new lease cannot acquire an old receipt ID, even if a session ID is reused.
CREATE TABLE c02_session_receipts (
    id char(26) PRIMARY KEY,
    lease_id char(26) NOT NULL REFERENCES c02_session_lease_ids(id),
    operation varchar(80) NOT NULL,
    key_digest char(64) NOT NULL,
    request_digest char(64) NOT NULL,
    result_ciphertext text NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (lease_id, operation, key_digest),
    CHECK (key_digest ~ '^[0-9a-f]{64}$' AND request_digest ~ '^[0-9a-f]{64}$')
);
CREATE OR REPLACE FUNCTION c02_session_receipt_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NOT EXISTS (SELECT 1 FROM c02_session_leases WHERE id = NEW.lease_id) THEN
            RAISE EXCEPTION 'Active session lease required';
        END IF;
        RETURN NEW;
    END IF;
    IF TG_OP <> 'UPDATE' OR OLD.result_ciphertext IS NOT NULL OR NEW.result_ciphertext IS NULL
       OR ROW(NEW.id, NEW.lease_id, NEW.operation, NEW.key_digest, NEW.request_digest, NEW.created_at)
          IS DISTINCT FROM ROW(OLD.id, OLD.lease_id, OLD.operation, OLD.key_digest, OLD.request_digest, OLD.created_at) THEN
        RAISE EXCEPTION 'Session receipt history is immutable';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER c02_session_receipt_guard BEFORE INSERT OR UPDATE OR DELETE ON c02_session_receipts
  FOR EACH ROW EXECUTE FUNCTION c02_session_receipt_guard();
CREATE TRIGGER c02_session_receipt_no_truncate BEFORE TRUNCATE ON c02_session_receipts
  FOR EACH STATEMENT EXECUTE FUNCTION c02_session_receipt_guard();
CREATE OR REPLACE FUNCTION c02_session_receipt_complete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM c02_session_receipts WHERE id = NEW.id AND result_ciphertext IS NULL) THEN
        RAISE EXCEPTION 'Incomplete session receipt cannot commit';
    END IF;
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER c02_session_receipt_complete AFTER INSERT OR UPDATE ON c02_session_receipts
  DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION c02_session_receipt_complete();
SQL);
    }

    public function down(): void
    {
        if (DB::table('c02_session_receipts')->exists()) {
            throw new LogicException('Cannot discard session idempotency history.');
        }
        Schema::dropIfExists('c02_session_receipts');
        Schema::dropIfExists('c02_session_leases');
        Schema::dropIfExists('c02_session_lease_ids');
        DB::unprepared('DROP FUNCTION IF EXISTS c02_session_lease_guard(); DROP FUNCTION IF EXISTS c02_session_lease_id_guard(); DROP FUNCTION IF EXISTS c02_session_receipt_guard(); DROP FUNCTION IF EXISTS c02_session_receipt_complete();');
    }
};
