-- Read catalog state through temporary helpers; no persistent application objects.
-- Capture output outside Git and protect it: it includes function definitions.
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL search_path = pg_catalog;
SELECT set_config('souzpir.transition_runtime', :'runtime_role', true) AS ignored_runtime \gset
SELECT set_config('souzpir.transition_database', :'expected_database', true) AS ignored_database \gset
SELECT set_config('souzpir.transition_port', :'expected_port', true) AS ignored_port \gset
DO $guard$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_event_trigger WHERE evtenabled <> 'D') THEN
    RAISE EXCEPTION 'Enabled event triggers require separate review before creating temporary helpers';
  END IF;
  IF current_setting('souzpir.transition_port') !~ '^[1-9][0-9]{0,4}$' THEN
    RAISE EXCEPTION 'Explicit numeric local port required';
  END IF;
  IF inet_server_addr() IS DISTINCT FROM '127.0.0.1'::inet
     OR inet_server_port() <> current_setting('souzpir.transition_port')::integer
     OR current_database() <> current_setting('souzpir.transition_database')
     OR NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname=current_setting('souzpir.transition_runtime')) THEN
    RAISE EXCEPTION 'Expected explicit local database, port and existing runtime role';
  END IF;
END $guard$;
\ir transition-snapshot.sql
SELECT pg_temp.transition_snapshot();
ROLLBACK;
