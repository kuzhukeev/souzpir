"""Exercise the opt-in format with a separate local Apache and synthetic URLs."""

import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import unittest


ROOT = Path(__file__).resolve().parents[5]
SNIPPET = ROOT / "infra/osp/apache-password-access-log.conf"
APACHE_ROOT = Path("C:/OSPanel/modules/Apache")
HTTPD = APACHE_ROOT / "bin/httpd.exe"


@unittest.skipUnless(os.name == "nt" and HTTPD.is_file(), "OSPanel Apache on Windows required")
class ApachePasswordAccessLogTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        if Path.cwd().resolve() != ROOT.resolve():
            raise RuntimeError("Run from the checkout that contains this test")
        local_root = (ROOT / ".local").resolve()
        if not local_root.is_relative_to(ROOT.resolve()) or local_root == ROOT.resolve():
            raise RuntimeError("Local runtime must be inside this checkout")
        tmp_root = ROOT / ".local/tmp"
        tmp_root.mkdir(parents=True, exist_ok=True)
        if not tmp_root.resolve().is_relative_to(local_root):
            raise RuntimeError("Temporary directory must be inside this checkout")
        cls.temp = tempfile.TemporaryDirectory(prefix="e101g-apache-", dir=tmp_root)
        cls.root = Path(cls.temp.name).resolve()
        if not cls.root.is_relative_to(tmp_root.resolve()):
            raise RuntimeError("Apache fixture is outside this worktree")
        cls.process = None
        try:
            cls._start_server()
        except BaseException:
            cls._stop_server()
            raise

    @classmethod
    def _start_server(cls):
        (cls.root / "www").mkdir()
        (cls.root / "www/ordinary.txt").write_text("ok", encoding="utf-8")
        (cls.root / "www/404.txt").write_text("not found", encoding="utf-8")
        (cls.root / ("www/synthetic-secret-" + "a" * 48)).write_text("rewritten", encoding="utf-8")
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as reservation:
            reservation.bind(("127.0.0.1", 0))
            cls.port = reservation.getsockname()[1]
        cls.log = cls.root / "access.log"
        config = cls.root / "httpd.conf"
        config.write_text(
            f'''ServerRoot "{APACHE_ROOT.as_posix()}"
ServerName 127.0.0.1
Listen 127.0.0.1:{cls.port}
LoadModule log_config_module modules/mod_log_config.so
LoadModule setenvif_module modules/mod_setenvif.so
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule rewrite_module modules/mod_rewrite.so
PidFile "{(cls.root / "httpd.pid").as_posix()}"
ErrorLog "{(cls.root / "error.log").as_posix()}"
LogLevel warn
DocumentRoot "{(cls.root / "www").as_posix()}"
<Directory />
    AllowOverride None
    Require all denied
</Directory>
<Directory "{(cls.root / "www").as_posix()}">
    AllowOverride None
    Require all granted
</Directory>
ErrorDocument 404 /404.txt
RewriteEngine On
RewriteRule ^/reset-password/([^/]+)$ /$1 [PT,L]
Include "{SNIPPET.as_posix()}"
CustomLog "{cls.log.as_posix()}" souzpir_password_safe_access
''',
            encoding="ascii",
        )
        env = os.environ.copy()
        env.pop("souzpir_safe_access_uri", None)
        checked = subprocess.run([str(HTTPD), "-t", "-f", str(config)], capture_output=True, text=True, env=env, timeout=15)
        if checked.returncode:
            raise RuntimeError("Isolated Apache configuration is invalid: " + checked.stderr)
        cls.process = subprocess.Popen(
            [str(HTTPD), "-X", "-f", str(config)],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            env=env,
            creationflags=subprocess.CREATE_NO_WINDOW,
        )
        deadline = time.monotonic() + 10
        while time.monotonic() < deadline:
            if cls.process.poll() is not None:
                raise RuntimeError("Isolated Apache exited before accepting requests")
            try:
                with socket.create_connection(("127.0.0.1", cls.port), timeout=0.2):
                    return
            except OSError:
                time.sleep(0.05)
        raise RuntimeError("Isolated Apache did not start")

    @classmethod
    def _stop_server(cls):
        if cls.process and cls.process.poll() is None:
            cls.process.terminate()
            try:
                cls.process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                cls.process.kill()
                cls.process.wait(timeout=10)
        if cls.root.resolve().is_relative_to((ROOT / ".local/tmp").resolve()):
            cls.temp.cleanup()
        else:
            raise RuntimeError("Refusing to clean an Apache fixture outside this worktree")

    @classmethod
    def tearDownClass(cls):
        try:
            cls._stop_server()
        finally:
            super().tearDownClass()

    def test_ordinary_and_legacy_encoded_requests_are_logged_without_secrets(self):
        token = "synthetic-secret-" + "a" * 48
        encoded_token = "%73" + token[1:]
        email = "access-log-probe@example.test"
        encoded_email = "%61ccess-log-probe%40example.test"
        cases = [
            ("/ordinary.txt?contact=" + email, "/ordinary.txt"),
            ("/missing.txt?contact=" + email, "/missing.txt"),
            ("/%6frdinary.txt", "-"),
            ("/reset-password/" + token + "?email=" + email, "-"),
            ("/%72eset-password/" + token + "?email=access-log-probe%40example.test", "-"),
            ("/reset-password/" + encoded_token + "?email=" + encoded_email, "-"),
            ("/reset%2dpassword/" + token + "?email=" + email, "-"),
            ("/reset-passwor%64/" + token + "?email=" + email, "-"),
            ("/RESET-PASSWORD%2f" + token + "?email=" + email, "-"),
            ("/%2572eset-password/" + token + "?email=" + email, "-"),
            ("/other/../reset-password/" + token + "?email=" + email, "-"),
            ("/other/%2e%2e/reset-password/" + token + "?email=" + email, "-"),
            ("http://127.0.0.1/reset-password/" + token + "?email=" + email, "-"),
            ("/reset-password;" + token + "?email=" + email, "-"),
            ("/ordinary.txt", "/ordinary.txt"),
        ]
        statuses = []
        for index, (target, _) in enumerate(cases):
            request = (
                f"GET {target} HTTP/1.1\r\nHost: 127.0.0.1\r\n"
                f"Referer: http://example.test/reset-password/{token}?email={email}\r\n"
                f"User-Agent: synthetic-{token}\r\n"
                "Connection: close\r\n\r\n"
            )
            with socket.create_connection(("127.0.0.1", self.port), timeout=5) as conn:
                conn.sendall(request.encode("ascii"))
                response = b""
                while data := conn.recv(65536):
                    response += data
            self.assertTrue(response.startswith(b"HTTP/1."), "Apache did not answer")
            statuses.append(response.split(b" ", 2)[1].decode("ascii"))
            if index == 0 and not response.startswith(b"HTTP/1.1 200"):
                self.fail("Ordinary fixture failed: " + (self.root / "error.log").read_text(encoding="utf-8", errors="replace"))
        deadline = time.monotonic() + 5
        lines = []
        while time.monotonic() < deadline:
            if self.log.exists():
                lines = self.log.read_text(encoding="utf-8").splitlines()
                if len(lines) >= len(cases):
                    break
            time.sleep(0.05)
        self.assertEqual(len(cases), len(lines), "Every request must have exactly one access record")
        self.assertEqual("200", statuses[0])
        self.assertEqual("404", statuses[1])
        self.assertEqual("200", statuses[2], "An encoded ordinary URL must still be logged")
        self.assertEqual("200", statuses[3], "The sensitive URL must be internally rewritten before logging")
        self.assertEqual("200", statuses[-1])
        for (_, expected_path), status, line in zip(cases, statuses, lines):
            self.assertIn(f'"GET {expected_path} HTTP/1.1" {status}', line)
        for line in lines:
            self.assertNotIn(token, line)
            self.assertNotIn(encoded_token, line)
            self.assertNotIn(email, line)
            self.assertNotIn("access-log-probe%40example.test", line)
            self.assertNotIn(encoded_email, line)


if __name__ == "__main__":
    unittest.main()
