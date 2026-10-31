-- Test-only bootstrap for a fresh, isolated database. Run as the migration
-- owner with psql -v ON_ERROR_STOP=1 -v expected_database=... -v runtime_role=... .
-- This file refuses database names outside the test prefix and mixed owners;
-- callers must also independently verify the server and data directory.
\set ON_ERROR_STOP on
BEGIN;
SELECT set_config('souzpir.expected_database', :'expected_database', true);
SELECT set_config('souzpir.runtime_role', :'runtime_role', true);

DO $guard$
DECLARE
    runtime_name text := current_setting('souzpir.runtime_role');
    runtime_oid oid;
    runtime_row pg_roles%ROWTYPE;
    database_owner oid;
BEGIN
    IF current_database() <> current_setting('souzpir.expected_database')
       OR current_database() !~ '^souzpir_test_[a-z0-9_]+$' THEN
        RAISE EXCEPTION 'This bootstrap requires the explicitly named isolated test database';
    END IF;
    SELECT datdba INTO database_owner FROM pg_database WHERE datname = current_database();
    IF database_owner <> (SELECT oid FROM pg_roles WHERE rolname = current_user)
       OR (SELECT rolsuper FROM pg_roles WHERE rolname = current_user) THEN
        RAISE EXCEPTION 'Connect as the non-superuser migration database owner';
    END IF;
    SELECT * INTO runtime_row FROM pg_roles WHERE rolname = runtime_name;
    IF NOT FOUND OR runtime_name = current_user THEN
        RAISE EXCEPTION 'Distinct existing runtime role required';
    END IF;
    runtime_oid := runtime_row.oid;
    IF NOT runtime_row.rolcanlogin OR runtime_row.rolsuper OR runtime_row.rolcreatedb
       OR runtime_row.rolcreaterole OR runtime_row.rolreplication OR runtime_row.rolbypassrls
       OR EXISTS (SELECT 1 FROM pg_auth_members WHERE member = runtime_oid)
       OR has_parameter_privilege(runtime_oid, 'session_replication_role', 'SET') THEN
        RAISE EXCEPTION 'Runtime role must not have elevated attributes or role memberships';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
               WHERE n.nspname !~ '^pg_' AND n.nspname <> 'information_schema' AND c.relowner = runtime_oid)
       OR EXISTS (SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                  WHERE n.nspname !~ '^pg_' AND n.nspname <> 'information_schema' AND p.proowner = runtime_oid)
       OR EXISTS (SELECT 1 FROM pg_namespace WHERE nspname !~ '^pg_' AND nspname <> 'information_schema'
                  AND nspowner = runtime_oid) THEN
        RAISE EXCEPTION 'Runtime role already owns objects; use the reviewed transition plan';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
               WHERE n.nspname = 'public' AND c.relkind IN ('r','p','v','m','f','S')
                 AND c.relowner <> (SELECT oid FROM pg_roles WHERE rolname = current_user)) THEN
        RAISE EXCEPTION 'Mixed object owners require a separately reviewed transition';
    END IF;
    IF to_regclass('public.migrations') IS NULL THEN
        RAISE EXCEPTION 'Apply application migrations as owner before bootstrapping runtime';
    END IF;
END $guard$;

REVOKE CREATE ON SCHEMA public FROM PUBLIC;
DO $grant$
DECLARE
    runtime_name text := current_setting('souzpir.runtime_role');
    migration_sequence regclass := pg_get_serial_sequence('public.migrations', 'id')::regclass;
BEGIN
    EXECUTE format('REVOKE ALL ON SCHEMA public FROM %I', runtime_name);
    EXECUTE format('GRANT USAGE ON SCHEMA public TO %I', runtime_name);
    EXECUTE format('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM %I', runtime_name);
    EXECUTE format('REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM %I', runtime_name);
    EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO %I', runtime_name);
    EXECUTE format('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO %I', runtime_name);
    EXECUTE format('REVOKE ALL ON TABLE public.migrations FROM %I', runtime_name);
    IF migration_sequence IS NOT NULL THEN
        EXECUTE format('REVOKE ALL ON SEQUENCE %s FROM %I', migration_sequence, runtime_name);
    END IF;
    EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO %I', runtime_name);
    EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO %I', runtime_name);
    IF has_schema_privilege(runtime_name, 'public', 'CREATE')
       OR has_database_privilege(runtime_name, current_database(), 'CREATE')
       OR has_table_privilege(runtime_name, 'public.migrations', 'INSERT')
       OR has_table_privilege(runtime_name, 'public.migrations', 'UPDATE')
       OR has_table_privilege(runtime_name, 'public.migrations', 'DELETE')
       OR has_any_column_privilege(runtime_name, 'public.migrations', 'INSERT')
       OR has_any_column_privilege(runtime_name, 'public.migrations', 'UPDATE')
       OR (migration_sequence IS NOT NULL
           AND (has_sequence_privilege(runtime_name, migration_sequence, 'USAGE')
             OR has_sequence_privilege(runtime_name, migration_sequence, 'UPDATE')))
       OR EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                  WHERE n.nspname = 'public' AND c.relkind IN ('r','p')
                    AND (has_table_privilege(runtime_name, c.oid, 'TRUNCATE')
                      OR has_table_privilege(runtime_name, c.oid, 'TRIGGER')
                      OR has_table_privilege(runtime_name, c.oid, 'REFERENCES'))) THEN
        RAISE EXCEPTION 'Runtime has excessive effective privileges, including through PUBLIC';
    END IF;
END $grant$;
COMMIT;
