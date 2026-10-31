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
