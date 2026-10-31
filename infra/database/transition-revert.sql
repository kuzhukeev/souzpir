-- Revert only the exact state recorded by transition-apply.sql.
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL search_path = pg_catalog;
SELECT set_config('souzpir.transition_runtime', :'runtime_role', true);
SELECT set_config('souzpir.transition_database', :'expected_database', true);
SELECT set_config('souzpir.transition_host', :'expected_host', true);
SELECT set_config('souzpir.transition_port', :'expected_port', true);
SELECT set_config('souzpir.transition_mode', :'execution_mode', true);
SELECT set_config('souzpir.transition_approval', :'approval', true);
SELECT set_config('souzpir.transition_review', :'review_reference', true);
SELECT set_config('souzpir.transition_backup', :'backup_reference', true);
DO $event_guard$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_event_trigger WHERE evtenabled <> 'D') THEN
    RAISE EXCEPTION 'Enabled event triggers require separate review before any transition DDL';
  END IF;
  IF current_setting('souzpir.transition_port') !~ '^[1-9][0-9]{0,4}$' THEN
    RAISE EXCEPTION 'Explicit numeric local port required';
  END IF;
  IF current_setting('souzpir.transition_host') !~ '^127\.([0-9]{1,3}\.){2}[0-9]{1,3}$' THEN
    RAISE EXCEPTION 'Explicit IPv4 loopback address required';
  END IF;
  IF NOT (current_setting('souzpir.transition_host')::inet <<= '127.0.0.0/8'::inet)
     OR inet_server_addr() IS DISTINCT FROM current_setting('souzpir.transition_host')::inet
     OR inet_server_port() <> current_setting('souzpir.transition_port')::integer
     OR current_database() <> current_setting('souzpir.transition_database') THEN
    RAISE EXCEPTION 'Expected exact loopback address, database and port';
  END IF;
  IF NOT ((current_setting('souzpir.transition_mode')='isolated_test'
           AND current_database() ~ '^souzpir_test_[a-z0-9_]+$')
          OR (current_setting('souzpir.transition_mode')='reviewed_existing'
              AND current_setting('souzpir.transition_approval')='INDEPENDENT_REVIEW_AND_VERIFIED_BACKUP'
              AND length(trim(current_setting('souzpir.transition_review'))) >= 8
              AND length(trim(current_setting('souzpir.transition_backup'))) >= 8)) THEN
    RAISE EXCEPTION 'Explicit test mode or independent review and verified backup references required';
  END IF;
  IF to_regclass('sp_e106_transition.state') IS NULL THEN
    RAISE EXCEPTION 'Transition state does not exist';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM sp_e106_transition.state s
                 WHERE s.database_name=current_database() AND s.migration_owner=current_user
                   AND s.runtime_role=current_setting('souzpir.transition_runtime')
                   AND s.execution_mode=current_setting('souzpir.transition_mode')
                   AND s.review_reference=current_setting('souzpir.transition_review')
                   AND s.backup_reference=current_setting('souzpir.transition_backup')) THEN
    RAISE EXCEPTION 'Transition identity does not match recorded state';
  END IF;
END $event_guard$;
\ir transition-snapshot.sql

DO $revert$
DECLARE
  role_name text := current_setting('souzpir.transition_runtime');
  saved sp_e106_transition.state%ROWTYPE;
  obj record;
  migration_sequence regclass := pg_get_serial_sequence('public.migrations', 'id')::regclass;
BEGIN
  SELECT * INTO STRICT saved FROM sp_e106_transition.state;
  IF saved.database_name<>current_database() OR saved.migration_owner<>current_user
     OR saved.runtime_role<>role_name OR saved.execution_mode<>current_setting('souzpir.transition_mode')
     OR saved.review_reference<>current_setting('souzpir.transition_review')
     OR saved.backup_reference<>current_setting('souzpir.transition_backup') OR saved.after_state IS NULL
     OR saved.after_state IS DISTINCT FROM pg_temp.transition_snapshot() THEN
    RAISE EXCEPTION 'Transition identity or catalog changed; refusing automatic revert';
  END IF;
  FOR obj IN SELECT c.oid, c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
             WHERE n.nspname='public' AND c.relkind IN ('r','p','v','m','f','S')
               AND c.oid <> 'public.migrations'::regclass
               AND (migration_sequence IS NULL OR c.oid <> migration_sequence)
  LOOP
    IF obj.relkind='S' THEN
      EXECUTE format('REVOKE USAGE, SELECT ON SEQUENCE %s FROM %I', obj.oid::regclass, role_name);
    ELSE
      EXECUTE format('REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLE %s FROM %I', obj.oid::regclass, role_name);
    END IF;
  END LOOP;
  FOR obj IN SELECT function_oid FROM sp_e106_transition.function_grants LOOP
    EXECUTE format('REVOKE EXECUTE ON FUNCTION %s FROM %I', obj.function_oid::regprocedure, role_name);
  END LOOP;
  EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM %I', role_name);
  EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE USAGE, SELECT ON SEQUENCES FROM %I', role_name);
  EXECUTE format('REVOKE USAGE ON SCHEMA public FROM %I', role_name);
  EXECUTE format('REVOKE CONNECT, TEMPORARY ON DATABASE %I FROM %I', current_database(), role_name);
  IF saved.before_state IS DISTINCT FROM pg_temp.transition_snapshot() THEN
    RAISE EXCEPTION 'Revert did not restore the original catalog ACL and owners; rolling back';
  END IF;
END $revert$;
DROP TABLE sp_e106_transition.function_grants;
DROP TABLE sp_e106_transition.state;
DROP SCHEMA sp_e106_transition;
COMMIT;
