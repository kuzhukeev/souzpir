-- Candidate operator script; use on an existing local database only after an
-- independent review and a verified backup. Merely naming a test database is
-- not proof of isolation: first verify the server and its data directory.
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

DO $guard$
DECLARE
  role_name text := current_setting('souzpir.transition_runtime');
  r pg_roles%ROWTYPE;
  migration_sequence regclass := pg_get_serial_sequence('public.migrations', 'id')::regclass;
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
     OR current_database() <> current_setting('souzpir.transition_database')
     OR NOT EXISTS (SELECT 1 FROM pg_database WHERE datname=current_database()
                    AND datdba=(SELECT oid FROM pg_roles WHERE rolname=current_user))
     OR (SELECT rolsuper FROM pg_roles WHERE rolname=current_user) THEN
    RAISE EXCEPTION 'Expected exact loopback address, port, database and its non-superuser owner';
  END IF;
  IF NOT ((current_setting('souzpir.transition_mode')='isolated_test'
           AND current_database() ~ '^souzpir_test_[a-z0-9_]+$')
          OR (current_setting('souzpir.transition_mode')='reviewed_existing'
              AND current_setting('souzpir.transition_approval')='INDEPENDENT_REVIEW_AND_VERIFIED_BACKUP'
              AND length(trim(current_setting('souzpir.transition_review'))) >= 8
              AND length(trim(current_setting('souzpir.transition_backup'))) >= 8)) THEN
    RAISE EXCEPTION 'Explicit test mode or independent review and verified backup references required';
  END IF;
  SELECT * INTO r FROM pg_roles WHERE rolname=role_name;
  IF NOT FOUND OR role_name=current_user OR NOT r.rolcanlogin OR r.rolsuper OR r.rolcreatedb
     OR r.rolcreaterole OR r.rolreplication OR r.rolbypassrls
     OR EXISTS (SELECT 1 FROM pg_auth_members WHERE member=r.oid)
     OR has_parameter_privilege(r.oid, 'session_replication_role', 'SET') THEN
    RAISE EXCEPTION 'Runtime must be a separate unprivileged login without memberships';
  END IF;
  IF to_regclass('public.migrations') IS NULL OR migration_sequence IS NULL OR to_regnamespace('sp_e106_transition') IS NOT NULL
     OR EXISTS (SELECT 1 FROM pg_namespace WHERE nspname !~ '^pg_' AND nspname NOT IN ('public','information_schema')) THEN
    RAISE EXCEPTION 'Missing migrations or its sequence, existing transition, or unsupported extra schema';
  END IF;
  IF EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
             WHERE n.nspname='public' AND (c.relowner<> (SELECT oid FROM pg_roles WHERE rolname=current_user)
                                            OR c.relrowsecurity OR c.relforcerowsecurity))
     OR EXISTS (SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                WHERE n.nspname='public' AND (p.proowner=r.oid OR p.prosecdef OR p.prokind<>'f'))
     OR EXISTS (SELECT 1 FROM pg_namespace WHERE nspname='public' AND nspowner=r.oid) THEN
    RAISE EXCEPTION 'Mixed relation owners, row security, runtime-owned functions, SECURITY DEFINER or unsupported routines';
  END IF;
  -- Rollback removes only rights created here; preexisting direct ACLs are refused.
  IF EXISTS (SELECT 1 FROM pg_namespace n CROSS JOIN LATERAL aclexplode(n.nspacl) a WHERE a.grantee=r.oid)
     OR EXISTS (SELECT 1 FROM pg_class c CROSS JOIN LATERAL aclexplode(c.relacl) a WHERE a.grantee=r.oid)
     OR EXISTS (SELECT 1 FROM pg_attribute c CROSS JOIN LATERAL aclexplode(c.attacl) a WHERE a.grantee=r.oid)
     OR EXISTS (SELECT 1 FROM pg_proc p CROSS JOIN LATERAL aclexplode(p.proacl) a WHERE a.grantee=r.oid)
     OR EXISTS (SELECT 1 FROM pg_default_acl d CROSS JOIN LATERAL aclexplode(d.defaclacl) a WHERE a.grantee=r.oid)
     OR EXISTS (SELECT 1 FROM pg_parameter_acl p CROSS JOIN LATERAL aclexplode(p.paracl) a WHERE a.grantee=r.oid)
     OR EXISTS (SELECT 1 FROM pg_database d CROSS JOIN LATERAL aclexplode(d.datacl) a WHERE a.grantee=r.oid) THEN
    RAISE EXCEPTION 'Runtime already has direct grants; separately review existing ACL';
  END IF;
  IF has_schema_privilege(role_name, 'public', 'CREATE') OR has_database_privilege(role_name, current_database(), 'CREATE')
     OR has_table_privilege(role_name, 'public.migrations', 'INSERT')
     OR has_table_privilege(role_name, 'public.migrations', 'UPDATE')
     OR has_table_privilege(role_name, 'public.migrations', 'DELETE')
     OR has_any_column_privilege(role_name, 'public.migrations', 'INSERT')
     OR has_any_column_privilege(role_name, 'public.migrations', 'UPDATE')
     OR (migration_sequence IS NOT NULL AND (has_sequence_privilege(role_name, migration_sequence, 'USAGE')
                                             OR has_sequence_privilege(role_name, migration_sequence, 'UPDATE')))
     OR EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                WHERE n.nspname='public' AND c.relkind IN ('r','p','v','m','f')
                AND (has_table_privilege(role_name,c.oid,'TRUNCATE') OR has_table_privilege(role_name,c.oid,'TRIGGER')
                     OR has_table_privilege(role_name,c.oid,'REFERENCES'))) THEN
    RAISE EXCEPTION 'Runtime has excessive effective privileges, including via PUBLIC';
  END IF;
  IF EXISTS (SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
             WHERE n.nspname='public' AND NOT has_function_privilege(role_name,p.oid,'EXECUTE')
               AND NOT has_function_privilege(current_user,p.oid,'EXECUTE WITH GRANT OPTION')) THEN
    RAISE EXCEPTION 'Owner cannot grant a required function; review its owner and ACL';
  END IF;
END $guard$;

\ir transition-snapshot.sql
CREATE SCHEMA sp_e106_transition;
REVOKE ALL ON SCHEMA sp_e106_transition FROM PUBLIC;
CREATE TABLE sp_e106_transition.state (
  database_name text NOT NULL, migration_owner text NOT NULL, runtime_role text NOT NULL,
  execution_mode text NOT NULL, review_reference text NOT NULL, backup_reference text NOT NULL,
  before_state jsonb NOT NULL, after_state jsonb
);
CREATE TABLE sp_e106_transition.function_grants (function_oid oid PRIMARY KEY);
-- Do not persist catalog/function snapshots when pre-existing default ACLs
-- would expose the metadata schema or tables to another role or PUBLIC.
DO $metadata_guard$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_namespace n CROSS JOIN LATERAL aclexplode(coalesce(n.nspacl, acldefault('n', n.nspowner))) a
             WHERE n.nspname='sp_e106_transition' AND a.grantee<>n.nspowner)
     OR EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                CROSS JOIN LATERAL aclexplode(coalesce(c.relacl, acldefault('r', c.relowner))) a
                WHERE n.nspname='sp_e106_transition' AND c.relkind IN ('r','p') AND a.grantee<>c.relowner) THEN
    RAISE EXCEPTION 'Default ACL would expose transition metadata; review before retrying';
  END IF;
END $metadata_guard$;
INSERT INTO sp_e106_transition.state VALUES
  (current_database(), current_user, current_setting('souzpir.transition_runtime'),
   current_setting('souzpir.transition_mode'), current_setting('souzpir.transition_review'),
   current_setting('souzpir.transition_backup'), pg_temp.transition_snapshot(), NULL);

DO $apply$
DECLARE
  role_name text := current_setting('souzpir.transition_runtime');
  obj record;
  migration_sequence regclass := pg_get_serial_sequence('public.migrations', 'id')::regclass;
BEGIN
  EXECUTE format('GRANT CONNECT, TEMPORARY ON DATABASE %I TO %I', current_database(), role_name);
  EXECUTE format('GRANT USAGE ON SCHEMA public TO %I', role_name);
  FOR obj IN SELECT c.oid, c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
             WHERE n.nspname='public' AND c.relkind IN ('r','p','v','m','f','S')
               AND c.oid <> 'public.migrations'::regclass
               AND (migration_sequence IS NULL OR c.oid <> migration_sequence)
  LOOP
    IF obj.relkind='S' THEN
      EXECUTE format('GRANT USAGE, SELECT ON SEQUENCE %s TO %I', obj.oid::regclass, role_name);
    ELSE
      EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE %s TO %I', obj.oid::regclass, role_name);
    END IF;
  END LOOP;
  FOR obj IN SELECT p.oid FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
             WHERE n.nspname='public' AND NOT has_function_privilege(role_name,p.oid,'EXECUTE')
  LOOP
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO %I', obj.oid::regprocedure, role_name);
    INSERT INTO sp_e106_transition.function_grants VALUES (obj.oid);
  END LOOP;
  EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO %I', role_name);
  EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO %I', role_name);
END $apply$;
UPDATE sp_e106_transition.state SET after_state=pg_temp.transition_snapshot();
COMMIT;
