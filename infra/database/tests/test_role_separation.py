"""Integration checks against a disposable, worktree-local PostgreSQL cluster.

The caller must explicitly supply the test DSN, preview DB and local data path.
No shared or non-loopback database is accepted. psql authenticates using the
environment's normal mechanism; never pass a password on the command line.
"""

import os
from pathlib import Path
import re
import subprocess
import unittest
import uuid


ROOT = Path(__file__).resolve().parents[3]
BOOTSTRAP = ROOT / 'infra' / 'database' / 'grant-isolated-runtime.sql'


class RoleSeparationTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.psql = os.environ['SP_ROLE_TEST_PSQL']
        cls.host = os.environ['SP_ROLE_TEST_HOST']
        cls.port = os.environ['SP_ROLE_TEST_PORT']
        cls.db = os.environ['SP_ROLE_TEST_DB']
        cls.preview = os.environ['SP_ROLE_PREVIEW_DB']
        cls.admin = os.environ['SP_ROLE_TEST_ADMIN']
        cls.owner = os.environ['SP_ROLE_TEST_OWNER']
        cls.runtime = os.environ['SP_ROLE_TEST_RUNTIME']
        cls.data = Path(os.environ['SP_ROLE_TEST_DATA']).resolve(strict=True)
        local = (ROOT / '.local').resolve(strict=True)
        if (cls.host != '127.0.0.1' or not cls.port.isdigit()
            or not re.fullmatch(r'souzpir_test_[a-z0-9_]+', cls.db)
            or not cls.preview or cls.preview == cls.db
            or len({cls.admin, cls.owner, cls.runtime}) != 3
            or any(not re.fullmatch(r'[a-z][a-z0-9_]{0,62}', x) for x in (cls.admin, cls.owner, cls.runtime))
            or not cls.data.is_relative_to(local)):
            raise RuntimeError('Refusing non-isolated role test configuration')
        cls.env = {k: v for k, v in os.environ.items() if not k.upper().startswith('PG')}
        cls.env['PGCONNECT_TIMEOUT'] = '5'
        # Verify the actual server, not merely configured env vars, before DML/DDL.
        data = cls.sql(cls.admin, 'SHOW data_directory', database='postgres').stdout.strip()
        if Path(data).resolve(strict=True) != cls.data:
            raise RuntimeError('PostgreSQL data directory is not the worktree-local test cluster')
        identity = cls.sql(cls.owner, "SELECT current_database() || '|' || current_user").stdout.strip()
        if identity != f'{cls.db}|{cls.owner}':
            raise RuntimeError('Migration connection did not reach the expected test database and role')
        identity = cls.sql(cls.runtime, "SELECT current_database() || '|' || current_user").stdout.strip()
        if identity != f'{cls.db}|{cls.runtime}':
            raise RuntimeError('Runtime connection did not reach the expected test database and role')
        if cls.sql(cls.owner, "SELECT to_regclass('public.migrations') IS NOT NULL").stdout.strip() != 't':
            raise RuntimeError('Apply the real application migrations to the isolated test database first')

    @classmethod
    def command(cls, role, database=None):
        return [cls.psql, '-X', '-q', '-A', '-t', '-v', 'ON_ERROR_STOP=1', '-v', 'VERBOSITY=sqlstate',
                '-h', cls.host, '-p', cls.port, '-U', role, '-d', database or cls.db]

    @classmethod
    def sql(cls, role, sql, *, database=None, check=True):
        return subprocess.run(cls.command(role, database), input=sql, text=True, encoding='utf-8',
                              capture_output=True, env=cls.env, check=check, timeout=30)

    def assert_denied(self, sql):
        result = self.sql(self.runtime, sql, check=False)
        self.assertNotEqual(0, result.returncode)
        self.assertIn('42501', result.stderr)

    def test_runtime_is_not_owner_or_privileged_and_cannot_change_security(self):
        facts = self.sql(self.runtime, """
            SELECT (NOT r.rolsuper AND NOT r.rolcreatedb AND NOT r.rolcreaterole
                AND NOT r.rolreplication AND NOT r.rolbypassrls
                AND NOT EXISTS (SELECT 1 FROM pg_auth_members m WHERE m.member=r.oid)
                AND NOT has_schema_privilege(r.oid,'public','CREATE')
                AND NOT has_parameter_privilege(r.oid,'session_replication_role','SET')
                AND NOT EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                                WHERE n.nspname='public' AND c.relowner=r.oid)
                AND NOT EXISTS (SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                                WHERE n.nspname='public' AND p.proowner=r.oid))
            FROM pg_roles r WHERE r.rolname=current_user;
        """).stdout.strip()
        self.assertEqual('t', facts)
        self.assert_denied('ALTER TABLE public.knowledge_releases DISABLE TRIGGER knowledge_release_immutable_trigger;')
        self.assert_denied('ALTER TABLE public.knowledge_releases DISABLE TRIGGER ALL;')
        self.assert_denied('BEGIN; ALTER FUNCTION public.knowledge_release_immutable() RENAME TO sp_e106_unwanted; ROLLBACK;')
        self.assert_denied('SET session_replication_role = replica;')
        self.assert_denied(f'SET ROLE "{self.owner}";')
        self.assert_denied('CREATE TABLE public.sp_e106_unwanted (id integer);')
        self.assert_denied('CREATE SCHEMA sp_e106_unwanted;')
        self.assert_denied('DELETE FROM public.migrations;')
        self.assert_denied("SELECT nextval(pg_get_serial_sequence('public.migrations','id')::regclass);")
        self.assert_denied('TRUNCATE public.users;')

    def test_runtime_app_crud_and_real_immutable_trigger(self):
        # Fixtures are transaction-local and exercise actual migrated application
        # tables; no real user, email or organization is read or retained.
        ids = [uuid.uuid4().hex[:26] for _ in range(4)]
        user, org, brand, release = ids
        result = self.sql(self.runtime, f"""
            BEGIN;
            INSERT INTO users(id,name,email,password) VALUES('{user}','test','{user}@example.test','test');
            INSERT INTO organizations(id,name,owner_user_id) VALUES('{org}','test','{user}');
            INSERT INTO brands(id,organization_id,name) VALUES('{brand}','{org}','test');
            INSERT INTO memberships(id,organization_id,user_id,role) VALUES('{uuid.uuid4().hex[:26]}','{org}','{user}','member');
            UPDATE users SET name='updated' WHERE id='{user}';
            INSERT INTO knowledge_releases(id,organization_id,brand_id,status,checksum,published_at)
              VALUES('{release}','{org}','{brand}','published',repeat('a',64),now());
            DO $test$
            BEGIN
              BEGIN
                UPDATE knowledge_releases SET version=version+1 WHERE id='{release}';
                RAISE EXCEPTION 'immutable trigger unexpectedly allowed update' USING ERRCODE='P0T01';
              EXCEPTION WHEN raise_exception THEN
                IF SQLERRM <> 'Published knowledge releases are immutable' THEN RAISE; END IF;
              END;
            END $test$;
            SELECT (SELECT name='updated' FROM users WHERE id='{user}') AND
                   (SELECT version=1 FROM knowledge_releases WHERE id='{release}');
            ROLLBACK;
        """)
        self.assertEqual('t', result.stdout.strip())

    def app_environment(self):
        env = dict(self.env)
        for name in ('DATABASE_URL', 'DB_URL', 'OPENAI_API_KEY', 'OPENROUTER_API_KEY',
                     'ANTHROPIC_API_KEY', 'GEMINI_API_KEY', 'TELEGRAM_BOT_TOKEN'):
            env.pop(name, None)
        env.update({
            'APP_ENV': 'testing', 'APP_BASE_PATH': str(ROOT),
            # Public, disposable test key; never read or reuse a working .env.
            'APP_KEY': 'base64:AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyA=',
            'DB_CONNECTION': 'pgsql', 'DB_HOST': self.host, 'DB_PORT': self.port,
            'DB_DATABASE': self.db, 'DB_USERNAME': self.runtime, 'DB_PASSWORD': '',
            'DB_TEST_DATABASE': self.db, 'APP_PREVIEW_DATABASE': self.preview,
            'AI_DRIVER': 'fake', 'DELIVERY_DRIVER': 'fake', 'MAIL_MAILER': 'array',
            'CACHE_STORE': 'array', 'SESSION_DRIVER': 'array', 'QUEUE_CONNECTION': 'sync',
            'FEATURES_SCHEDULING_ENABLED': 'false', 'BCRYPT_ROUNDS': '4',
        })
        return env

    def run_app(self, env):
        php = os.environ['SP_ROLE_TEST_PHP']
        return subprocess.run([php, '-d', 'opcache.enable_cli=0',
                               str(ROOT / 'infra/database/tests/runtime-app-smoke.php')],
                              cwd=ROOT, env=env, text=True, encoding='utf-8',
                              capture_output=True, timeout=30)

    def test_runtime_eloquent_application_operations(self):
        env = self.app_environment()
        result = self.run_app(env)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn('application_runtime_dml_passed', result.stdout)

    def test_application_smoke_refuses_wrong_server_data_directory(self):
        env = self.app_environment()
        env['SP_ROLE_TEST_DATA'] = str(self.data.parent)
        result = self.run_app(env)
        self.assertNotIn('application_runtime_dml_passed', result.stdout)
        self.assertIn('data directory is not the worktree-local test cluster', result.stdout + result.stderr)

    def test_future_owner_migration_receives_runtime_defaults(self):
        table = 'sp_e106_role_probe_' + uuid.uuid4().hex[:12]
        try:
            self.sql(self.owner, f'CREATE TABLE public."{table}" (id bigint GENERATED BY DEFAULT AS IDENTITY, label text NOT NULL);')
            self.sql(self.owner, f'ALTER TABLE public."{table}" ADD COLUMN note text;')
            result = self.sql(self.runtime, f"""
                INSERT INTO public."{table}" (label) VALUES ('created');
                UPDATE public."{table}" SET label='updated';
                SELECT label FROM public."{table}";
                DELETE FROM public."{table}";
            """)
            self.assertEqual('updated', result.stdout.strip())
            self.assertEqual('t', self.sql(self.runtime, f"""
                SELECT nextval(pg_get_serial_sequence('public.{table}', 'id')::regclass) > 0;
            """).stdout.strip())
            self.assert_denied(f'ALTER TABLE public."{table}" DISABLE TRIGGER ALL;')
        finally:
            self.sql(self.owner, f'DROP TABLE IF EXISTS public."{table}";')

    def test_bootstrap_rejects_wrong_target_and_owner_as_runtime(self):
        for database, role in [('wrong_database', self.runtime), (self.db, self.owner), (self.db, self.admin)]:
            command = self.command(self.owner) + ['-v', f'expected_database={database}', '-v', f'runtime_role={role}', '-f', str(BOOTSTRAP)]
            result = subprocess.run(command, text=True, encoding='utf-8', capture_output=True, env=self.env, timeout=30)
            self.assertNotEqual(0, result.returncode)
        self.assertEqual('f', self.sql(self.runtime, "SELECT has_schema_privilege(current_user,'public','CREATE')").stdout.strip())

    def test_bootstrap_rejects_role_membership_and_owned_objects(self):
        command = self.command(self.owner) + ['-v', f'expected_database={self.db}',
                   '-v', f'runtime_role={self.runtime}', '-f', str(BOOTSTRAP)]
        self.sql(self.admin, f'GRANT "{self.owner}" TO "{self.runtime}";')
        try:
            result = subprocess.run(command, text=True, encoding='utf-8', capture_output=True, env=self.env, timeout=30)
            self.assertNotEqual(0, result.returncode)
        finally:
            self.sql(self.admin, f'REVOKE "{self.owner}" FROM "{self.runtime}";')

        table = 'sp_e106_owned_probe_' + uuid.uuid4().hex[:12]
        self.sql(self.owner, f'CREATE TABLE public."{table}" (id integer);')
        try:
            self.sql(self.admin, f'ALTER TABLE public."{table}" OWNER TO "{self.runtime}";')
            result = subprocess.run(command, text=True, encoding='utf-8', capture_output=True, env=self.env, timeout=30)
            self.assertNotEqual(0, result.returncode)
        finally:
            self.sql(self.admin, f'DROP TABLE IF EXISTS public."{table}";')

        table = 'sp_e106_mixed_probe_' + uuid.uuid4().hex[:12]
        self.sql(self.owner, f'CREATE TABLE public."{table}" (id integer);')
        try:
            self.sql(self.admin, f'ALTER TABLE public."{table}" OWNER TO "{self.admin}";')
            result = subprocess.run(command, text=True, encoding='utf-8', capture_output=True, env=self.env, timeout=30)
            self.assertNotEqual(0, result.returncode)
        finally:
            self.sql(self.admin, f'DROP TABLE IF EXISTS public."{table}";')

    def test_bootstrap_rejects_public_column_grant_and_rolls_back(self):
        command = self.command(self.owner) + ['-v', f'expected_database={self.db}',
                   '-v', f'runtime_role={self.runtime}', '-f', str(BOOTSTRAP)]
        self.sql(self.owner, 'GRANT CREATE ON SCHEMA public TO PUBLIC; GRANT UPDATE (batch) ON public.migrations TO PUBLIC;')
        try:
            result = subprocess.run(command, text=True, encoding='utf-8', capture_output=True, env=self.env, timeout=30)
            self.assertNotEqual(0, result.returncode)
            self.assertEqual('t', self.sql(self.runtime,
                "SELECT has_any_column_privilege(current_user,'public.migrations','UPDATE')").stdout.strip())
            # Its attempted REVOKE was rolled back along with the rest of the bootstrap.
            self.assertEqual('t', self.sql(self.runtime,
                "SELECT has_schema_privilege(current_user,'public','CREATE')").stdout.strip())
            self.assertEqual('t', self.sql(self.runtime,
                "SELECT has_table_privilege(current_user,'public.users','INSERT')").stdout.strip())
        finally:
            self.sql(self.owner, 'REVOKE UPDATE (batch) ON public.migrations FROM PUBLIC; REVOKE CREATE ON SCHEMA public FROM PUBLIC;')


if __name__ == '__main__':
    unittest.main()
