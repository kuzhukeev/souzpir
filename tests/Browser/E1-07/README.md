# Изолированная browser-проверка SP-E1-07

Запускать из корня собственного worktree `sp-e1-07` только с отдельным PostgreSQL-кластером на `127.0.0.1:55607`, базой `souzpir_test_sp_e1_07` и непривилегированной ролью `souzpir_sp_e1_07`. Предварительно проверить `basePath`, фактическую БД/роль и что preview DB отличается. `.env.testing` должна принадлежать только этому worktree, использовать file-сессии и `MAIL_MAILER=log`; реальные доставки и scheduler отключены. Fixture до миграции повторно проверяет эти условия, подключение и collision markers.

Для теста на локальной машине, после создания изолированного окружения:

```powershell
$env:APP_ENV = 'testing'
$env:E107_FIXTURE_ENABLED = '1'
$env:E107_FIXTURE_ORG = '01J00000000000000000000000'
$env:SUPPORT_MAIL_ENABLED = 'false'
$env:SUPPORT_SMTP_ENABLED = 'false'
$env:NOTIFICATIONS_EXTERNAL_ENABLED = 'false'
$env:MAIL_MAILER = 'log'
$env:AI_DRIVER = 'fake'
$env:DELIVERY_DRIVER = 'fake'
$env:FEATURES_SCHEDULING_ENABLED = 'false'
Remove-Item Env:SUPPORT_RECIPIENT -ErrorAction SilentlyContinue
& ./infra/osp/php.ps1 tests/Browser/E1-07/prepare-fixture.php
npm run build
& 'C:/Program Files/Git/usr/bin/openssl.exe' req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=127.0.0.1' -addext 'subjectAltName=IP:127.0.0.1' -keyout .local/e107-test-key.pem -out .local/e107-test-cert.pem
[IO.File]::WriteAllText((Join-Path (Get-Location) '.local/e107-proxy-token'), [Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)), [Text.UTF8Encoding]::new($false))
```

В двух отдельных терминалах из того же каталога запустить команды. Для PHP-терминала повторить переменные выше и добавить `E107_HTTPS_HARNESS=1`:

```powershell
$env:E107_HTTPS_HARNESS = '1'
& C:/OSPanel/modules/PHP-8.4/php.exe -d sys_temp_dir=.local/tmp -S 127.0.0.1:8108 tests/Browser/E1-07/tls-router.php
```

```powershell
node tests/Browser/E1-07/tls-proxy.mjs
```

Затем в первом окружении запустить `RUN_BILLING_E2E=1` и `./node_modules/.bin/playwright.cmd test --config tests/Browser/E1-07/playwright.config.ts --workers 1`. После проверки остановить оба процесса. Proxy принимает только TLS на loopback, удаляет все входные forwarding-заголовки и подставляет собственный `X-Forwarded-Proto`; одноразовый локальный token отделяет router от прямых запросов. Router устанавливает доверенный proxy только для `127.0.0.1` после проверки БД, роли и отключённых отправок. Browser→proxy использует HTTPS с локальным одноразовым сертификатом, proxy→PHP — HTTP только на loopback; тестовые данные синтетические. Сертификат, ключ, token и runtime остаются в `.local` и не коммитятся.

Матрица проверяет пять экранов, три темы и ширины, сохранение payer, заявку, историю и draft. Blur/focus и BFCache-события в headless частично моделируются; отдельный opt-in headed тест (`RUN_HEADED_FOCUS=1`, `--headed`) требует работающего переключения системного фокуса и не входит в обычный прогон.
