# SP-E1-08 browser verification

Run only inside the claimed task worktree. This fixture refuses any database other than `souzpir_test_sp_e1_08`, requires the runtime test database to match, and requires explicit `APP_ENV=testing` plus `E108_FIXTURE_ENABLED=1`. Coordinate the database slot with PHP tests: their migrations can remove browser fixtures.

Prepare the already isolated environment and build:

```powershell
$env:APP_ENV='testing'
$env:E108_FIXTURE_ENABLED='1'
& ./infra/osp/php.ps1 tests/Browser/E1-08/prepare-fixture.php
& node_modules/.bin/vite.cmd build
```

Start the HTTP server in a separate terminal, from this worktree. The explicit temporary directories are necessary for image uploads; `artisan serve` does not inherit the wrapper's PHP `-d` options in its child server:

```powershell
$env:APP_ENV='testing'
& 'C:/OSPanel/modules/PHP-8.4/php.exe' '-d' "sys_temp_dir=$PWD/.local/tmp" '-d' "upload_tmp_dir=$PWD/.local/tmp" '-S' '127.0.0.1:8238' '-t' 'public' 'tests/Browser/E1-08/server.php'
```

Start the visual fixture server in another terminal:

```powershell
& node_modules/.bin/vite.cmd --config tests/Browser/E1-08/vite.config.ts
```

Run the suites with separate artifact directories:

```powershell
$env:PROFILE_CHROMIUM_PATH='C:/Program Files/Google/Chrome/Application/chrome.exe'
& node_modules/.bin/playwright.cmd test tests/Browser/E1-08/profile-ui.spec.ts tests/Browser/E1-08/qr.spec.ts --workers=1 --output=.local/runtime/profile-ui-results
& node_modules/.bin/playwright.cmd test tests/Browser/E1-08/profile-e2e.spec.ts --workers=1 --output=.local/runtime/profile-e2e-results
```

Both browser suites block non-local network requests. Screenshots are ignored artifacts under `.local/screenshots/e1-08`. The visual suite uses synthetic page props and intercepted Inertia responses for edge cases. The E2E suite uses the real application, validation, private avatar storage, notification preferences/test record, authentication sessions, and MFA. Live email/Telegram deliveries are deliberately not exercised.

`qr-reference.json` contains independent ReportLab QR matrices (byte mode, correction L, mask 0), covering UTF-8, multiple versions and multi-block encoding. `qr-reference.py` regenerates them using an already installed local ReportLab runtime; no network or new application dependency is needed.

After successful E2E, leave the test database intact until the integrator records the audit snapshot. Then stop only these task-owned HTTP/Vite processes. Do not stop shared services or modify other worktrees.

## Local-hostname Telegram check

`local-http-telegram.spec.ts` checks real UI → local application → redirect and the full event matrix using synthetic users and a synthetic bot, with external requests blocked. Prepare the fixture against a dedicated database as above. Use a separate ignored `.env.local` with `APP_ENV=local`, `APP_URL=http://souzpir.local:8248`, the same **isolated test database and restricted test role**, `MAIL_MAILER=array`, fake delivery drivers, and synthetic `PROFILE_TELEGRAM_BOT_USERNAME` / `PROFILE_TELEGRAM_WEBHOOK_SECRET`. Verify `souzpir.local` resolves to `127.0.1.11` before starting. Never point this test server at a working database.

The PHP built-in server does not supply `SERVER_ADDR`, so the explicitly enabled **test-only** router supplies the known address of this process. It is not a production entry point and does not prove actual OSPanel/Apache behavior. Run from the worktree `public` directory:

```powershell
$env:APP_ENV='local'
$env:E108_LOCAL_HTTP_TEST='1'
& 'C:/OSPanel/modules/PHP-8.4/php.exe' '-d' "sys_temp_dir=$PWD/../.local/tmp" '-S' '127.0.1.11:8248' '../tests/Browser/E1-08/local-http-test-router.php'
```

From the worktree root run `node node_modules/@playwright/test/cli.js test tests/Browser/E1-08/local-http-telegram.spec.ts --workers=1`. Stop the dedicated server after the test. No real bot, webhook registration or delivery is exercised.
