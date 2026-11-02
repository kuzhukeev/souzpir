# Изолированная проверка маскирования access log Apache

`infra/osp/apache-password-access-log.conf` — образец формата для отдельной проверки, **не установленная конфигурация OSPanel**. Он не меняет действующий Apache и не включает отправку почты. Для работы формата нужны `mod_setenvif` и `mod_log_config` Apache 2.4.

Формат всегда пишет одну запись с адресом клиента, временем, методом, протоколом, результатом и размером ответа. Путь добавляется только если исходная строка запроса `THE_REQUEST` имеет однозначный незакодированный ASCII-путь и не содержит `reset-password` без учёта регистра. Для путей восстановления, кодированных или неоднозначных путей и неразобранных запросов вместо пути выводится `-`. Query, Referer, имя пользователя и User-Agent не записываются ни для каких запросов. Это намеренно сокращает детализацию обычных записей, но не отключает их.

Использование исходной строки и запись только проверенного значения в `souzpir_safe_access_uri`, а не `%r` или `%U`, важны при внутренних переходах: результирующий URI или путь обработчика может отличаться от исходного и содержать секрет. Другие правила конкретного сервера не должны перезаписывать эту переменную.

## Воспроизведение

На Windows с установленным `C:/OSPanel/modules/Apache/bin/httpd.exe` из корня проверяемого checkout:

```powershell
& 'C:/Users/eralash/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe' -m unittest discover -s tests/Feature/Shell/Auth/PasswordLogSafety -p 'test_*.py' -v
```

Тест создаёт отдельные конфигурацию, документы, PID и журналы во временном каталоге этого checkout, проверяет конфигурацию `httpd -t` и запускает отдельный Apache только на свободном loopback-порту. Реальными HTTP-запросами проверяются обычные 200/404, исходный и кодированные legacy URL, закодированные разделители и переходы, absolute-form и внутренний rewrite, превращающий путь восстановления в путь с одним токеном. Проверяется ровно одна access-запись на запрос, метод и статус, отсутствие synthetic token/email как в URL, так и в Referer. Процесс и только собственный временный каталог закрываются после теста; рабочий OSPanel, его журналы, сайты и база не используются.

В отдельной проверочной конфигурации используются эти директивы:

```apache
Include "<checkout>/infra/osp/apache-password-access-log.conf"
CustomLog "<isolated-log-directory>/access.log" souzpir_password_safe_access
```

Одного добавления такого `CustomLog` к действующему `combined` недостаточно: второй журнал продолжит получать полный `%r`. Перед любой будущей установкой нужно отдельно определить все эффективные `CustomLog` и их наследование для конкретного vhost, заменить каждый формат, проверить отсутствие других access-logger и изменения `souzpir_safe_access_uri`, а затем независимо выполнить реальные запросы к каждому фактическому входу и проверить все его журналы и копии. Сам этот файл никаких действий над конфигурацией сервера не выполняет.

## Граница проверки

Проверен только access log отдельно запущенного Apache и именно приведённый формат. **Защита error log, других логгеров, reverse proxy, сети наблюдения, старых копий журналов и полной цепочки ingress не подтверждена.** Во время отдельной неудачной пробы `core:alert` Apache записал синтетический Referer со старым URL в error log. Поэтому до отдельного решения и проверки всех точек реальную доставку восстановления включать нельзя; неизвестные proxy остаются неподтверждёнными. Системные настройки и OSPanel для этой проверки не менялись.

## Проверка новой fragment + POST ссылки

`infra/osp/apache-password-proxy-fixture.conf` запускает отдельный Apache OSPanel на `127.0.0.1:8242`, который проксирует на отдельный PHP fixture на `127.0.0.1:8241`. Это тестовая конфигурация, не инструкция менять действующий vhost. Подготовьте отдельный PostgreSQL на `127.0.0.1:55841`: база `souzpir_test_sp_e1_01g`, её непривилегированный владелец `sp_e101g_app`. Fixture выполняет `migrate:fresh`: никогда не указывайте рабочую базу. Установите зависимости в этом worktree и выполните `npm run build`.

В PowerShell из корня worktree задайте отдельные тестовые значения `APP_ENV=testing`, `APP_KEY`, `APP_URL=http://127.0.0.1:8242`, `APP_PREVIEW_DATABASE` (имя, отличное от тестовой базы), `CABINET_ORGANIZATION_ID=01J00000000000000000000000`, `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=55841`, `DB_DATABASE` и `DB_TEST_DATABASE` (оба — имя тестовой базы выше), `DB_USERNAME=sp_e101g_app`, `CACHE_STORE=file`, `SESSION_DRIVER=file`, `LOG_CHANNEL=single`, `MAIL_MAILER=array`, `E101G_BROWSER_FIXTURE=1`, `E101G_BROWSER_ROUTER=1`. `APP_STORAGE_PATH` должен указывать на `.local/runtime/testing/storage` этого worktree; создайте в нём `framework/cache/data`, `framework/sessions`, `framework/views` и `logs`.

```powershell
$env:E101G_APACHE_DIR = ((Get-Location).Path.Replace('\','/') + '/.local/runtime/testing/apache')
New-Item -ItemType Directory -Force $env:E101G_APACHE_DIR | Out-Null
$php = Start-Process 'C:/OSPanel/modules/PHP-8.4/php.exe' -ArgumentList '-d','sys_temp_dir=.local/tmp','-S','127.0.0.1:8241','-t','public','tests/Browser/E1-01/password-reset-router.php' -WindowStyle Hidden -PassThru
$apache = Start-Process 'C:/OSPanel/modules/Apache/bin/httpd.exe' -ArgumentList '-X','-f',((Get-Location).Path + '/infra/osp/apache-password-proxy-fixture.conf') -WindowStyle Hidden -PassThru
$env:E101G_BROWSER_BASE = 'http://127.0.0.1:8242'
$env:E101G_APACHE_LOG_DIR = '.local/runtime/testing/apache'
$env:PLAYWRIGHT_CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe'
npx playwright test tests/Browser/E1-01/password-reset-safety.spec.ts --workers=1
Stop-Process -Id $php.Id,$apache.Id
```

Тест использует синтетический адрес, проверяет очистку fragment до POST, URL запросов, заголовки, историю и три журнала; отдельный запрос вызывает контролируемую ошибку Apache. Проверьте, что выбранные порты свободны до запуска и остановлены только свои процессы. Тестовая конфигурация не устанавливается в OSPanel и не проверяет неизвестные серверы.

**Условие включения реальной доставки:** отдельно проверить HTTPS и access/error/application logs на каждом фактическом production-прокси, веб-сервере и сборщике журналов, включая их конфигурацию и копии. Старые URL могут попасть в журналы до Laravel; без проверки соответствующего входа безопасность не подтверждена. SMTP остаётся выключенным до операторской приёмки.
