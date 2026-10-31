"""Rehearse the role transition only on a disposable copy of a synthetic database."""

import copy
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import unittest
import uuid

import test_role_separation as common


ROOT = Path(__file__).resolve().parents[3]


class TransitionRehearsalTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        for field, key in (('psql', 'SP_ROLE_TEST_PSQL'), ('host', 'SP_ROLE_TEST_HOST'),
                           ('port', 'SP_ROLE_TEST_PORT'), ('db', 'SP_ROLE_TEST_DB'),
                           ('preview', 'SP_ROLE_PREVIEW_DB'), ('admin', 'SP_ROLE_TEST_ADMIN'),
                           ('owner', 'SP_ROLE_TEST_OWNER'), ('runtime', 'SP_ROLE_TEST_RUNTIME')):
            setattr(cls, field, os.environ[key])
        cls.data = Path(os.environ['SP_ROLE_TEST_DATA']).resolve(strict=True)
        local = (ROOT / '.local').resolve(strict=True)
        if (not common.is_ipv4_loopback(cls.host) or not cls.port.isdigit()
            or not re.fullmatch(r'souzpir_test_[a-z0-9_]+', cls.db)
            or not cls.preview or cls.preview == cls.db or not cls.data.is_relative_to(local)
            or len({cls.admin, cls.owner, cls.runtime}) != 3
            or any(not re.fullmatch(r'[a-z][a-z0-9_]{0,62}', x) for x in (cls.admin, cls.owner, cls.runtime))):
            raise RuntimeError('Refusing non-isolated transition rehearsal configuration')
        cls.env = {k: v for k, v in os.environ.items() if not k.upper().startswith('PG')}
        cls.env['PGCONNECT_TIMEOUT'] = '5'
        actual = cls.sql(cls.admin, 'SHOW data_directory', database='postgres').stdout.strip()
        if Path(actual).resolve(strict=True) != cls.data:
            raise RuntimeError('PostgreSQL data directory is not the worktree-local test cluster')
        address = cls.sql(cls.admin, "SELECT host(inet_server_addr()) || '|' || inet_server_port()", database='postgres').stdout.strip()
        if address != f'{cls.host}|{cls.port}':
            raise RuntimeError('PostgreSQL server address or port did not match the isolated test target')
        for role in (cls.owner, cls.runtime):
            identity = cls.sql(role, "SELECT current_database() || '|' || current_user").stdout.strip()
            if identity != f'{cls.db}|{role}':
                raise RuntimeError('Unexpected test database or role')
        # Synthetic fixtures live only in the dedicated copy and are removed after the tests.
        cls.suffix = uuid.uuid4().hex[:12]
        cls.fn = 'sp_e106_private_' + cls.suffix
        cls.email = f'transition-{cls.suffix}@example.test'
        cls.user_id = uuid.uuid4().hex[:26]
        cls.sql(cls.owner, f"""
            CREATE FUNCTION public."{cls.fn}"(integer) RETURNS integer
              LANGUAGE sql IMMUTABLE COST 101 AS 'SELECT $1 + 1';
            REVOKE ALL ON FUNCTION public."{cls.fn}"(integer) FROM PUBLIC;
            INSERT INTO public.users(id,name,email,password)
              VALUES ('{cls.user_id}','Synthetic transition user','{cls.email}','test-only');
        """)

    @classmethod
    def tearDownClass(cls):
        cls.sql(cls.owner, f"DROP FUNCTION IF EXISTS public.\"{cls.fn}\"(integer); DELETE FROM public.users WHERE id='{cls.user_id}';")

    @classmethod
    def command(cls, role, database=None):
        return [cls.psql, '-X', '-q', '-A', '-t', '-v', 'ON_ERROR_STOP=1', '-v', 'VERBOSITY=sqlstate',
                '-h', cls.host, '-p', cls.port, '-U', role, '-d', database or cls.db]

    @classmethod
    def sql(cls, role, sql, *, database=None, check=True):
        return subprocess.run(cls.command(role, database), input=sql, text=True, encoding='utf-8',
                              capture_output=True, env=cls.env, check=check, timeout=30)

    def script(self, name, *, check=True, mode='isolated_test', approval='none', port=None,
               database=None, runtime=None, review='synthetic-review', backup='synthetic-backup', expected_host=None, verbose=False):
        command = self.command(self.owner)
        if verbose:
            command += ['-v', 'VERBOSITY=default']
        command += ['-v', f'expected_database={database or self.db}',
                  '-v', f'runtime_role={runtime or self.runtime}', '-v', f'expected_host={self.host if expected_host is None else expected_host}',
                  '-v', f'expected_port={port or self.port}',
                  '-v', f'execution_mode={mode}', '-v', f'approval={approval}',
                  '-v', f'review_reference={review}', '-v', f'backup_reference={backup}',
                  '-f', str(ROOT / 'infra/database' / name)]
        return subprocess.run(command, text=True, encoding='utf-8', capture_output=True,
                              env=self.env, check=check, timeout=30)

    def snapshot(self, expected_host=None):
        command = self.command(self.owner) + ['-v', f'expected_database={self.db}',
                  '-v', f'runtime_role={self.runtime}', '-v', f'expected_host={self.host if expected_host is None else expected_host}',
                  '-v', f'expected_port={self.port}',
                  '-f', str(ROOT / 'infra/database/transition-inventory.sql')]
        result = subprocess.run(command, cwd=ROOT, text=True, encoding='utf-8', capture_output=True,
                                env=self.env, check=True, timeout=30)
        return json.loads(result.stdout.strip())

    def test_fresh_migrations_with_null_sequence_acl_roundtrip(self):
        # A newly migrated DB is essential: any previous grants can materialize
        # relacl and hide an incorrect acldefault() normalization.
        suffix = uuid.uuid4().hex[:12]
        database = 'souzpir_test_e106_fresh_' + suffix
        runtime = 'sp_e106_fresh_' + suffix
        created_role = False
        created_database = False
        try:
            self.sql(self.admin, f'CREATE ROLE "{runtime}" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;', database='postgres')
            created_role = True
            self.sql(self.admin, f'CREATE DATABASE "{database}" OWNER "{self.owner}" TEMPLATE template0;', database='postgres')
            created_database = True
            actual = self.sql(self.admin, 'SHOW data_directory', database='postgres').stdout.strip()
            if Path(actual).resolve(strict=True) != self.data:
                raise RuntimeError('Unexpected test cluster before migrations')
            identity = self.sql(self.owner, "SELECT current_database() || '|' || current_user", database=database).stdout.strip()
            if identity != f'{database}|{self.owner}':
                raise RuntimeError('Unexpected fresh database before migrations')
            env = common.RoleSeparationTest.app_environment(self)
            env.update({'DB_DATABASE': database, 'DB_TEST_DATABASE': database, 'DB_USERNAME': self.owner,
                        'SP_ROLE_TEST_DB': database, 'SP_ROLE_TEST_RUNTIME': runtime})
            php = os.environ['SP_ROLE_TEST_PHP']
            preflight = r'''
                require 'vendor/autoload.php';
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                $db = Illuminate\Support\Facades\DB::connection();
                $identity = $db->selectOne('select current_database() as db, current_user as role, host(inet_server_addr()) as address, inet_server_port() as port');
                $root = realpath(getcwd());
                $ok = $app->environment('testing') && realpath($app->basePath()) === $root
                    && realpath(Illuminate\Foundation\Application::inferBasePath()) === $root
                    && $db->getDriverName() === 'pgsql' && $identity->db === getenv('DB_TEST_DATABASE')
                    && $identity->role === getenv('DB_USERNAME') && $identity->address === getenv('SP_ROLE_TEST_HOST')
                    && (string) $identity->port === getenv('SP_ROLE_TEST_PORT')
                    && config('runtime.test_database') === $identity->db
                    && config('runtime.preview_database') !== $identity->db;
                if (! $ok) { throw new RuntimeException('Unsafe fresh migration preflight'); }
                echo 'fresh_migration_preflight_ok';
            '''
            result = subprocess.run([php, '-d', 'opcache.enable_cli=0', '-r', preflight], cwd=ROOT, env=env,
                                    text=True, encoding='utf-8', capture_output=True, timeout=30)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertIn('fresh_migration_preflight_ok', result.stdout)
            migrate = subprocess.run([php, '-d', 'opcache.enable_cli=0', 'artisan', 'migrate', '--env=testing',
                                      '--force', '--no-interaction'], cwd=ROOT, env=env,
                                     text=True, encoding='utf-8', capture_output=True, timeout=60)
            self.assertEqual(0, migrate.returncode, migrate.stdout + migrate.stderr)

            names = ('jobs_id_seq', 'failed_jobs_id_seq', 'personal_access_tokens_id_seq')
            rows = self.sql(self.owner, """
                SELECT relname, relacl IS NULL FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                WHERE n.nspname='public' AND c.relkind='S'
                  AND relname IN ('jobs_id_seq','failed_jobs_id_seq','personal_access_tokens_id_seq') ORDER BY relname;
            """, database=database).stdout.strip().splitlines()
            self.assertEqual([name + '|t' for name in sorted(names)], rows, 'Fresh migrations must retain NULL sequence ACL')

            def run_file(name):
                command = self.command(self.owner, database=database) + ['-v', 'VERBOSITY=default',
                    '-v', f'expected_database={database}', '-v', f'expected_host={self.host}',
                    '-v', f'expected_port={self.port}', '-v', f'runtime_role={runtime}',
                    '-v', 'execution_mode=isolated_test', '-v', 'approval=none',
                    '-v', 'review_reference=synthetic-review', '-v', 'backup_reference=synthetic-backup',
                    '-f', str(ROOT / 'infra/database' / name)]
                return subprocess.run(command, cwd=ROOT, env=self.env, text=True, encoding='utf-8',
                                      capture_output=True, timeout=30)

            inventory = run_file('transition-inventory.sql')
            self.assertEqual(0, inventory.returncode, inventory.stderr)
            baseline = json.loads(inventory.stdout.strip())
            for name in names:
                row = next(row for row in baseline['relations'] if row[1:3] == ['public', name])
                owner_privileges = {item[2] for item in row[-1] if item[1] == baseline['database'][1]}
                self.assertEqual({'USAGE', 'SELECT', 'UPDATE'}, owner_privileges,
                                 f'{name}: normalized owner privileges are {sorted(owner_privileges)}')

            applied = run_file('transition-apply.sql')
            self.assertEqual(0, applied.returncode, applied.stderr)
            saved = json.loads(self.sql(self.owner, 'SELECT after_state FROM sp_e106_transition.state;', database=database).stdout.strip())
            inventory = run_file('transition-inventory.sql')
            self.assertEqual(0, inventory.returncode, inventory.stderr)
            current = json.loads(inventory.stdout.strip())
            changed = sorted(key for key in saved if saved[key] != current.get(key))
            self.assertFalse(changed, f'Immediately after apply, saved/current snapshot sections differ: {changed}')
            reverted = run_file('transition-revert.sql')
            self.assertEqual(0, reverted.returncode, reverted.stderr)
            inventory = run_file('transition-inventory.sql')
            self.assertEqual(0, inventory.returncode, inventory.stderr)
            restored = json.loads(inventory.stdout.strip())
            changed = sorted(key for key in baseline if baseline[key] != restored.get(key))
            self.assertFalse(changed, f'After revert, baseline/restored snapshot sections differ: {changed}')
        finally:
            if created_database:
                self.sql(self.admin, f'DROP DATABASE "{database}";', database='postgres')
            if created_role:
                self.sql(self.admin, f'DROP ROLE "{runtime}";', database='postgres')

    @staticmethod
    def without_new_runtime_grants(snapshot, runtime):
        projected = copy.deepcopy(snapshot)
        role_oid = next(role[0] for role in projected['roles'] if role[1] == runtime)

        def strip(acl):
            return [entry for entry in acl if entry[1] != role_oid]

        projected['database'][-1] = strip(projected['database'][-1])
        for category in ('schemas', 'relations', 'columns', 'functions', 'defaults', 'parameters'):
            for item in projected[category] or []:
                item[-1] = strip(item[-1])
        projected['defaults'] = [item for item in projected['defaults'] if item[-1]]
        return projected

    def test_refuses_existing_direct_runtime_acl(self):
        self.sql(self.owner, f'GRANT SELECT ON public.users TO "{self.runtime}";')
        try:
            result = self.script('transition-apply.sql', check=False)
            self.assertNotEqual(0, result.returncode)
            self.assertEqual('f', self.sql(self.owner, "SELECT to_regnamespace('sp_e106_transition') IS NOT NULL").stdout.strip())
            self.assertEqual('t', self.sql(self.runtime, "SELECT has_table_privilege(current_user,'public.users','SELECT')").stdout.strip())
        finally:
            self.sql(self.owner, f'REVOKE SELECT ON public.users FROM "{self.runtime}";')

    def test_refuses_security_definer_function(self):
        name = 'sp_e106_definer_' + self.suffix
        self.sql(self.owner, f'CREATE FUNCTION public."{name}"() RETURNS integer LANGUAGE sql SECURITY DEFINER AS \'SELECT 1\';')
        try:
            result = self.script('transition-apply.sql', check=False)
            self.assertNotEqual(0, result.returncode)
            self.assertEqual('f', self.sql(self.owner, "SELECT to_regnamespace('sp_e106_transition') IS NOT NULL").stdout.strip())
        finally:
            self.sql(self.owner, f'DROP FUNCTION public."{name}"();')

    def test_refuses_row_level_security_that_owner_could_bypass(self):
        self.sql(self.owner, 'ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;')
        try:
            result = self.script('transition-apply.sql', check=False)
            self.assertNotEqual(0, result.returncode)
            self.assertEqual('f', self.sql(self.owner, "SELECT to_regnamespace('sp_e106_transition') IS NOT NULL").stdout.strip())
        finally:
            self.sql(self.owner, 'ALTER TABLE public.users DISABLE ROW LEVEL SECURITY;')

    def test_refuses_unreviewed_mode_and_wrong_port(self):
        for options in ({'mode': 'reviewed_existing', 'approval': 'none'},
                        {'mode': 'reviewed_existing', 'approval': 'INDEPENDENT_REVIEW_AND_VERIFIED_BACKUP', 'backup': ' '},
                        {'port': str(int(self.port) + 1)}, {'database': 'souzpir_test_wrong_target'}):
            result = self.script('transition-apply.sql', check=False, **options)
            self.assertNotEqual(0, result.returncode)
            self.assertEqual('f', self.sql(self.owner, "SELECT to_regnamespace('sp_e106_transition') IS NOT NULL").stdout.strip())

    def test_refuses_wrong_or_non_loopback_expected_address(self):
        wrong = '127.0.0.1' if self.host != '127.0.0.1' else '127.0.1.48'
        for candidate in (wrong, '192.0.2.1', '127.0.1.48/24', '127.0.999.1', ''):
            result = self.script('transition-apply.sql', check=False, expected_host=candidate)
            self.assertNotEqual(0, result.returncode)
            self.assertEqual('f', self.sql(self.owner, "SELECT to_regnamespace('sp_e106_transition') IS NOT NULL").stdout.strip())
            with self.assertRaises(subprocess.CalledProcessError):
                self.snapshot(expected_host=candidate)
        for name in ('transition-apply.sql', 'transition-inventory.sql', 'transition-revert.sql'):
            command = self.command(self.owner) + ['-v', f'expected_database={self.db}',
                      '-v', f'runtime_role={self.runtime}', '-v', f'expected_port={self.port}',
                      '-v', 'execution_mode=isolated_test', '-v', 'approval=none',
                      '-v', 'review_reference=synthetic-review', '-v', 'backup_reference=synthetic-backup',
                      '-f', str(ROOT / 'infra/database' / name)]
            result = subprocess.run(command, cwd=ROOT, text=True, encoding='utf-8', capture_output=True,
                                    env=self.env, timeout=30)
            self.assertNotEqual(0, result.returncode)
            self.assertIn('42601', result.stderr)

    def test_rehearsal_refuses_wrong_server_data_directory(self):
        env = dict(self.env)
        env['SP_ROLE_TEST_DATA'] = str(self.data.parent)
        result = subprocess.run([sys.executable, '-m', 'unittest', 'discover', '-s', 'infra/database/tests',
                                 '-p', 'test_transition_rehearsal.py'],
                                cwd=ROOT, env=env, text=True, encoding='utf-8', capture_output=True, timeout=30)
        self.assertNotEqual(0, result.returncode)
        self.assertIn('PostgreSQL data directory is not the worktree-local test cluster', result.stderr)

    @unittest.skipUnless(os.environ.get('SP_ROLE_TEST_HOST') == '127.0.1.48', 'requires isolated 127.0.1.48 listener')
    def test_explicit_os_panel_loopback_with_isolated_cluster(self):
        identity = self.sql(self.admin, "SELECT host(inet_server_addr()) || '|' || inet_server_port()", database='postgres').stdout.strip()
        self.assertEqual(f'127.0.1.48|{self.port}', identity)
        self.assertEqual(self.data, Path(self.sql(self.admin, 'SHOW data_directory', database='postgres').stdout.strip()).resolve(strict=True))
        baseline = self.snapshot(expected_host='127.0.1.48')
        self.script('transition-apply.sql', expected_host='127.0.1.48')
        try:
            self.assertNotEqual(baseline, self.snapshot(expected_host='127.0.1.48'))
        finally:
            self.script('transition-revert.sql', expected_host='127.0.1.48')
        self.assertEqual(baseline, self.snapshot(expected_host='127.0.1.48'))

    def test_refuses_privileged_replication_parameter(self):
        self.assertEqual('f', self.sql(self.owner,
            f"SELECT has_parameter_privilege('{self.runtime}','session_replication_role','SET')").stdout.strip())
        self.sql(self.admin, f'GRANT SET ON PARAMETER session_replication_role TO "{self.runtime}";')
        try:
            result = self.script('transition-apply.sql', check=False)
            self.assertNotEqual(0, result.returncode)
            self.assertEqual('f', self.sql(self.owner, "SELECT to_regnamespace('sp_e106_transition') IS NOT NULL").stdout.strip())
        finally:
            self.sql(self.admin, f'REVOKE SET ON PARAMETER session_replication_role FROM "{self.runtime}";')

    def test_refuses_role_membership_and_mixed_ownership(self):
        self.sql(self.admin, f'GRANT "{self.owner}" TO "{self.runtime}";')
        try:
            self.assertNotEqual(0, self.script('transition-apply.sql', check=False).returncode)
        finally:
            self.sql(self.admin, f'REVOKE "{self.owner}" FROM "{self.runtime}";')
        table = 'sp_e106_mixed_' + self.suffix
        self.sql(self.owner, f'CREATE TABLE public."{table}" (id integer);')
        try:
            self.sql(self.admin, f'ALTER TABLE public."{table}" OWNER TO "{self.admin}";')
            self.assertNotEqual(0, self.script('transition-apply.sql', check=False).returncode)
        finally:
            self.sql(self.admin, f'DROP TABLE IF EXISTS public."{table}";')

    def test_refuses_trigger_grant_through_public_on_view(self):
        view = 'sp_e106_view_' + self.suffix
        self.sql(self.owner, f'CREATE VIEW public."{view}" AS SELECT id FROM public.users; GRANT TRIGGER ON public."{view}" TO PUBLIC;')
        try:
            result = self.script('transition-apply.sql', check=False)
            self.assertNotEqual(0, result.returncode)
            self.assertEqual('f', self.sql(self.owner, "SELECT to_regnamespace('sp_e106_transition') IS NOT NULL").stdout.strip())
        finally:
            self.sql(self.owner, f'DROP VIEW public."{view}";')

    def test_refuses_default_acl_that_exposes_metadata(self):
        already = self.sql(self.owner, """
            SELECT EXISTS (SELECT 1 FROM pg_default_acl d CROSS JOIN LATERAL aclexplode(d.defaclacl) a
              WHERE d.defaclrole=(SELECT oid FROM pg_roles WHERE rolname=current_user)
                AND d.defaclnamespace=0 AND d.defaclobjtype='r' AND a.grantee=0 AND a.privilege_type='SELECT');
        """).stdout.strip()
        self.assertEqual('f', already)
        self.sql(self.owner, 'ALTER DEFAULT PRIVILEGES GRANT SELECT ON TABLES TO PUBLIC;')
        try:
            self.assertNotEqual(0, self.script('transition-apply.sql', check=False).returncode)
            self.assertEqual('f', self.sql(self.owner, "SELECT to_regnamespace('sp_e106_transition') IS NOT NULL").stdout.strip())
        finally:
            self.sql(self.owner, 'ALTER DEFAULT PRIVILEGES REVOKE SELECT ON TABLES FROM PUBLIC;')

    def test_refuses_enabled_event_trigger_before_creating_helpers(self):
        function = 'sp_e106_event_fn_' + self.suffix
        event = 'sp_e106_event_' + self.suffix
        self.sql(self.admin, f"""
            CREATE FUNCTION public."{function}"() RETURNS event_trigger LANGUAGE plpgsql AS $body$
            BEGIN IF TG_TAG='CREATE FUNCTION' THEN RAISE EXCEPTION 'event fired' USING ERRCODE='P0T02'; END IF; END
            $body$;
            CREATE EVENT TRIGGER "{event}" ON ddl_command_start EXECUTE FUNCTION public."{function}"();
        """)
        try:
            for script in ('transition-apply.sql', 'transition-revert.sql'):
                result = self.script(script, check=False)
                self.assertNotEqual(0, result.returncode)
                self.assertIn('P0001', result.stderr)
                self.assertNotIn('P0T02', result.stderr)
            command = self.command(self.owner) + ['-v', f'expected_database={self.db}',
                      '-v', f'runtime_role={self.runtime}', '-v', f'expected_host={self.host}', '-v', f'expected_port={self.port}',
                      '-f', str(ROOT / 'infra/database/transition-inventory.sql')]
            result = subprocess.run(command, cwd=ROOT, text=True, encoding='utf-8', capture_output=True,
                                    env=self.env, timeout=30)
            self.assertNotEqual(0, result.returncode)
            self.assertIn('P0001', result.stderr)
            self.assertNotIn('P0T02', result.stderr)
        finally:
            self.sql(self.admin, f'DROP EVENT TRIGGER IF EXISTS "{event}"; DROP FUNCTION IF EXISTS public."{function}"();')

    def test_isolated_mode_roundtrip(self):
        baseline = self.snapshot()
        self.script('transition-apply.sql')
        self.script('transition-revert.sql')
        self.assertEqual(baseline, self.snapshot())

    def test_roundtrip_preserves_owners_acls_functions_and_data(self):
        baseline = self.snapshot()
        before_private = self.sql(self.runtime, f"SELECT has_function_privilege(current_user,'public.{self.fn}(integer)','EXECUTE')").stdout.strip()
        self.assertEqual('f', before_private)
        mode = 'reviewed_existing'
        approval = 'INDEPENDENT_REVIEW_AND_VERIFIED_BACKUP'
        self.script('transition-apply.sql', mode=mode, approval=approval)
        transitioned = True
        changed_function = False
        changed_acl = False
        probe = 'sp_e106_future_' + self.suffix
        try:
            # Every normal table DML, sequence and owner-executable function is available.
            applied = self.snapshot()
            self.assertEqual(self.without_new_runtime_grants(baseline, self.runtime),
                             self.without_new_runtime_grants(applied, self.runtime))
            with tempfile.TemporaryDirectory(prefix='transition-inventory-', dir=ROOT / '.local') as tmp:
                before_file, after_file = Path(tmp) / 'before.json', Path(tmp) / 'after.json'
                before_file.write_text(json.dumps(baseline), encoding='utf-8')
                after_file.write_text(json.dumps(applied), encoding='utf-8')
                compare = [sys.executable, str(ROOT / 'infra/database/compare-transition-inventory.py'),
                           str(before_file), str(after_file), '--runtime', self.runtime]
                checked = subprocess.run(compare, text=True, capture_output=True, encoding='utf-8', timeout=30)
                self.assertEqual(0, checked.returncode, checked.stderr)
                old_format = copy.deepcopy(applied)
                old_format['format'] = 'souzpir-transition-inventory-v1'
                after_file.write_text(json.dumps(old_format), encoding='utf-8')
                self.assertNotEqual(0, subprocess.run(compare, text=True, capture_output=True,
                                                      encoding='utf-8', timeout=30).returncode)
                altered = copy.deepcopy(applied)
                role_oid = next(row[0] for row in altered['role_names'] if row[1] == self.runtime)
                users = next(row for row in altered['relations'] if row[1:3] == ['public', 'users'])
                users[-1].append([altered['database'][1], role_oid, 'TRUNCATE', False])
                after_file.write_text(json.dumps(altered), encoding='utf-8')
                self.assertNotEqual(0, subprocess.run(compare, text=True, capture_output=True,
                                                      encoding='utf-8', timeout=30).returncode)
                altered = copy.deepcopy(applied)
                users = next(row for row in altered['relations'] if row[1:3] == ['public', 'users'])
                next(entry for entry in users[-1] if entry[1] == role_oid and entry[2] == 'SELECT')[3] = True
                after_file.write_text(json.dumps(altered), encoding='utf-8')
                self.assertNotEqual(0, subprocess.run(compare, text=True, capture_output=True,
                                                      encoding='utf-8', timeout=30).returncode)
                altered = copy.deepcopy(applied)
                users = next(row for row in altered['relations'] if row[1:3] == ['public', 'users'])
                users[-1] = [entry for entry in users[-1]
                             if not (entry[1] == altered['database'][1] and entry[2] == 'SELECT')]
                after_file.write_text(json.dumps(altered), encoding='utf-8')
                self.assertNotEqual(0, subprocess.run(compare, text=True, capture_output=True,
                                                      encoding='utf-8', timeout=30).returncode)
            rights = self.sql(self.owner, f"""
                SELECT NOT EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                  WHERE n.nspname='public' AND c.relkind IN ('r','p','v','m','f') AND c.relname <> 'migrations'
                    AND (NOT has_table_privilege('{self.runtime}',c.oid,'SELECT')
                      OR NOT has_table_privilege('{self.runtime}',c.oid,'INSERT')
                      OR NOT has_table_privilege('{self.runtime}',c.oid,'UPDATE')
                      OR NOT has_table_privilege('{self.runtime}',c.oid,'DELETE')))
                  AND NOT EXISTS (SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                    WHERE n.nspname='public' AND has_function_privilege(current_user,p.oid,'EXECUTE')
                      AND NOT has_function_privilege('{self.runtime}',p.oid,'EXECUTE'))
                  AND NOT EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                    WHERE n.nspname='public' AND c.relkind='S'
                      AND c.oid <> pg_get_serial_sequence('public.migrations','id')::regclass
                      AND (NOT has_sequence_privilege('{self.runtime}',c.oid,'USAGE')
                        OR NOT has_sequence_privilege('{self.runtime}',c.oid,'SELECT')));
            """)
            self.assertEqual('t', rights.stdout.strip())
            self.assertEqual('42', self.sql(self.runtime, f'SELECT public."{self.fn}"(41);').stdout.strip())
            self.assertEqual('Synthetic transition user', self.sql(self.runtime,
                f"SELECT name FROM public.users WHERE id='{self.user_id}'").stdout.strip())
            denied = self.sql(self.runtime,
                'ALTER TABLE public.knowledge_releases DISABLE TRIGGER knowledge_release_immutable_trigger;', check=False)
            self.assertNotEqual(0, denied.returncode)
            self.assertIn('42501', denied.stderr)
            denied = self.sql(self.runtime, 'SELECT * FROM sp_e106_transition.state;', check=False)
            self.assertNotEqual(0, denied.returncode)
            self.assertIn('42501', denied.stderr)
            wrong_role = self.script('transition-revert.sql', check=False, mode=mode, approval=approval, runtime=self.owner)
            self.assertNotEqual(0, wrong_role.returncode)
            wrong = '127.0.0.1' if self.host != '127.0.0.1' else '127.0.1.48'
            wrong_host = self.script('transition-revert.sql', check=False, mode=mode, approval=approval, expected_host=wrong)
            self.assertNotEqual(0, wrong_host.returncode)
            self.assertEqual('42', self.sql(self.runtime, f'SELECT public."{self.fn}"(41);').stdout.strip())
            self.assertEqual('f', self.sql(self.runtime,
                "SELECT has_table_privilege(current_user,'public.migrations','DELETE')").stdout.strip())
            temp_result = self.sql(self.runtime, """
                BEGIN;
                CREATE TEMPORARY TABLE sp_e106_temp_probe (value integer);
                INSERT INTO sp_e106_temp_probe VALUES (1);
                SELECT value FROM sp_e106_temp_probe;
                SELECT pg_advisory_xact_lock(hashtextextended('sp-e106-transition-test',0));
                ROLLBACK;
            """)
            self.assertEqual('1', temp_result.stdout.strip())

            # Exercise the actual Laravel models under the new role, with fake external adapters.
            env = common.RoleSeparationTest.app_environment(self)
            app = common.RoleSeparationTest.run_app(self, env)
            self.assertIn('application_runtime_dml_passed', app.stdout)
            self.assertEqual(0, app.returncode, app.stderr)

            # Default ACLs give future owner-created tables and sequences the same working rights.
            self.sql(self.owner, f'CREATE TABLE public."{probe}" (id bigint GENERATED BY DEFAULT AS IDENTITY, label text);')
            try:
                result = self.sql(self.runtime, f"""
                  INSERT INTO public."{probe}"(label) VALUES ('ok');
                  SELECT label FROM public."{probe}";
                  SELECT nextval(pg_get_serial_sequence('public.{probe}','id')::regclass) > 0;
                """)
                self.assertEqual(['ok', 't'], result.stdout.strip().splitlines())
            finally:
                self.sql(self.owner, f'DROP TABLE public."{probe}";')

            # Refuse to overwrite unrelated changes made after the transition.
            self.sql(self.owner, f'ALTER FUNCTION public."{self.fn}"(integer) COST 102;')
            changed_function = True
            failed = self.script('transition-revert.sql', check=False, mode=mode, approval=approval, verbose=True)
            self.assertNotEqual(0, failed.returncode)
            self.assertIn('sections: functions', failed.stderr)
            self.assertIn(self.fn, failed.stderr)
            self.assertEqual('42', self.sql(self.runtime, f'SELECT public."{self.fn}"(41);').stdout.strip())
            self.sql(self.owner, f'ALTER FUNCTION public."{self.fn}"(integer) COST 101;')
            changed_function = False
            original_users = next(row for row in baseline['relations'] if row[1:3] == ['public', 'users'])
            self.assertFalse(any(entry[1] == '0' and entry[2] == 'SELECT' for entry in original_users[-1]))
            self.sql(self.owner, 'GRANT SELECT ON public.users TO PUBLIC;')
            changed_acl = True
            failed = self.script('transition-revert.sql', check=False, mode=mode, approval=approval, verbose=True)
            self.assertNotEqual(0, failed.returncode)
            self.assertIn('sections: relations', failed.stderr)
            self.assertIn('users', failed.stderr)
            self.sql(self.owner, 'REVOKE SELECT ON public.users FROM PUBLIC;')
            changed_acl = False
            self.script('transition-revert.sql', mode=mode, approval=approval)
            transitioned = False
            self.assertEqual(baseline, self.snapshot())
            self.assertEqual('f', self.sql(self.runtime,
                f"SELECT has_function_privilege(current_user,'public.{self.fn}(integer)','EXECUTE')").stdout.strip())
            self.assertEqual('Synthetic transition user', self.sql(self.owner,
                f"SELECT name FROM public.users WHERE id='{self.user_id}'").stdout.strip())
        finally:
            if changed_function:
                self.sql(self.owner, f'ALTER FUNCTION public."{self.fn}"(integer) COST 101;')
            if changed_acl:
                self.sql(self.owner, 'REVOKE SELECT ON public.users FROM PUBLIC;')
            if transitioned:
                self.script('transition-revert.sql', mode=mode, approval=approval)


if __name__ == '__main__':
    unittest.main()
