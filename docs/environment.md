# Локальная среда и независимые worktree

Проект использует PHP 8.4, Laravel 13, PostgreSQL 16, React 19, TypeScript, Inertia 3, Vite 8 и Tailwind 4. Точные версии зафиксированы в composer.lock/package-lock.json. Установленный Node 24.19.0 совместим; минимальные версии перечислены в package.json. Основа получена из official laravel/laravel v13.11.0, донорские приложения не устанавливались поверх проекта.

## Первый запуск после merge

Основной checkout: C:/OSPanel/home/souzpir.local. OSPanel должен обслуживать только public/; переключение выполняет интегратор до создания main .env. Корневой .htaccess дополнительно закрывает скрытые файлы, исходники, vendor и подготовительные материалы, если временно выбран корень checkout.

1. Скопировать .env.example в игнорируемый .env; назначить уникальный APP_INSTANCE и APP_URL.
2. Подставить credentials из собственного игнорируемого runtime-файла. Не копировать секреты в задачи/логи/коммиты.
3. Установить composer install и npm ci. Composer PHAR: C:/OSPanel/data/PHP-8.4/default/composer/composer.phar. PHP: C:/OSPanel/modules/PHP-8.4/php.exe; Node/npm: C:/Program Files/nodejs.
4. Создать ключ командой php artisan key:generate. Выполнить php artisan migrate; bootstrap автоматически обнаруживает database/migrations/bootstrap и последующие task directories.
5. Выполнить npm run build. Локальная страница /preview доступна только APP_ENV=local/testing. Никакие аккаунты, бизнес-объекты или внешние доставки при установке не создаются.

Для Windows CLI используйте infra/osp/php.ps1 artisan ... и infra/osp/php.ps1 C:/OSPanel/data/PHP-8.4/default/composer/composer.phar ... . Обёртка задаёт PHP sys_temp_dir внутри worktree .local/tmp: конфигурация OSPanel по умолчанию направляет временные файлы в общий C:/OSPanel/temp. Перед запуском Composer впервые создайте .local/tmp; обёртка делает это автоматически.

## PostgreSQL на этой машине

Версия 16.13, listen 127.0.1.48:5432, existing cluster C:/OSPanel/data/PostgreSQL-16/default; исполняемые файлы C:/OSPanel/modules/PostgreSQL-16/bin. Созданы только новые изолированные базы:

| Checkout | База preview | База tests | Ограниченная роль |
|---|---|---|---|
| main | souzpir_dev | souzpir_test_main | souzpir_app |
| sp-e0-01 | souzpir_sp_e0_01 | souzpir_test_sp_e0_01 | souzpir_sp_e0_01 |

Роли не superuser, не создают роли/БД и не имеют replication/bypassrls. PUBLIC CONNECT отозван на новых БД. Новые роли проверяют SCRAM пароль на loopback; правила других БД не менялись. Credentials лежат только в main .local/runtime/postgres.env и sp-e0-01.env.

PostgreSQL сейчас запущен через штатный pg_ctl start, который использует ограниченный Windows token. Прямой запуск postgres из OSPanel, работающего с elevated rights, PostgreSQL отвергает; UAC и политики Windows не менялись. Модуль PG16 в supervisor OSPanel отключён, чтобы два supervisor не запускали один кластер. Сервер работает отдельно от OSPanel, Windows service не создавался. Автостарт после перезапуска Windows/OSPanel не гарантирован.

- infra/osp/postgresql.ps1 status — проверка pg_isready.
- infra/osp/postgresql.ps1 start — hidden запуск существующего кластера; повтор безопасно сообщает already accepting.
- infra/osp/postgresql.ps1 stop — fast shutdown всего данного кластера; только после координации активных задач, поскольку они используют его совместно.

Не использовать Start-Process -Wait для pg_ctl: PowerShell ждёт всё дерево процессов включая постоянно работающий postgres. Обёртка ждёт только завершения launcher PID через WaitForExit. При следующей обычной пользовательской сессии можно вернуть штатный supervisor OSPanel после координированной остановки отдельного сервера; сейчас это не выполнено. Источник механизма: [PostgreSQL pg_ctl](https://www.postgresql.org/docs/16/app-pg-ctl.html), [официальный Windows launcher](https://github.com/postgres/postgres/blob/REL_16_STABLE/src/bin/pg_ctl/pg_ctl.c).

PHP8.4 pdo_pgsql/pgsql включены в persistent OSPanel template и проверены CLI + HTTP. Настройка только generated php.ini будет потеряна при regen. Для osp CLI требуется cmd.exe без PowerShell parent guard; не изменять сам osp.bat. Не перезапускать весь OSPanel для задач разработки.

## Изоляция каждого worktree

Каждый task получает собственные DB preview/test и ограниченную роль; нельзя использовать test_main параллельно с тестами main. Имена тестовых БД содержат отдельный сегмент test (например souzpir_test_sp_e0_01); APP_PREVIEW_DATABASE всегда явно указывает preview DB. Framework provider проверяет фактически разобранный connection (включая DB_URL), PostgreSQL/test marker/точный DB_TEST_DATABASE/различие с preview до запуска тестовых миграций.

CLI --env=testing также выбирает .env.testing и отдельный runtime; preview credentials не используются как fallback. Создайте .env.testing отдельно от .env: APP_ENV=testing, DB_DATABASE=собственная_test_база, APP_PREVIEW_DATABASE=собственная_preview_база. Прочие credentials доступны только локально. Для bootstrap integration tests нужен реальный PostgreSQL16; SQLite подстановка запрещена. В CI выделяется одноразовый сервис postgres:16 без внешних подключений.

APP_INSTANCE, CACHE_PREFIX, REDIS_PREFIX, SESSION_COOKIE, QUEUE_NAMESPACE должны быть уникальны для worktree и отдельны для testing. По умолчанию storage/config/routes/events/packages/services/view cache создаются внутри абсолютного <checkout>/.local/runtime; testing использует вложенную testing/. Можно переопределить APP_STORAGE_PATH, APP_CONFIG_CACHE, APP_ROUTES_CACHE, APP_EVENTS_CACHE, APP_SERVICES_CACHE, APP_PACKAGES_CACHE, VIEW_COMPILED_PATH только абсолютными путями внутри этого runtime. Переходы ../ и выход через junction отклоняются. Runtime создаёт минимальные каталоги сам, общий storage/ не нужен.

Порты также отдельные: main через OSPanel souzpir.local, E001 HTTP 127.0.0.1:8101 и Vite5174. Следующий task проверяет свободный порт и назначает собственный. Для отдельного сервера: infra/osp/php.ps1 artisan serve --host=127.0.0.1 --port=8101. Для frontend dev задайте VITE_DEV_PORT и npm run dev; Vite strictPort запрещает незаметную смену. Production build: npm run build.

QUEUE_CONNECTION=sync; именованные очереди в config('runtime.queues') имеют вид <instance>:seller-turns/vision/ai/notifications/default. Реальные workers и scheduler не запускаются. MAIL_MAILER=log, AI_DRIVER=fake, DELIVERY_DRIVER=fake, FEATURES_SCHEDULING_ENABLED=false. При отсутствии будущего adapter ошибку должен вернуть consumer; foundation не создаёт no-op production бизнес-реализаций.

Redis8.6.1 установлен, но не включён (назначенный OSPanel адрес127.0.1.55:6379). Mailpit1.29.3 установлен, но не включён; его текущая OSPanel конфигурация SMTP0.0.0.0:25/UI127.0.1.13:443 требует отдельного осознанного решения перед стартом. Не предполагать стандартные порты1025/8025 и не запускать эти службы ради первого preview.

## Проверки и extension points

composer lint / composer test; npm run lint / npm run typecheck / npm run test / npm run build. npm run test:browser требует локальный HTTP и установленный Chromium; для отдельной установки задайте PLAYWRIGHT_BROWSERS_PATH=<checkout>/.local/browsers перед npx playwright install chromium и перед тестом. В CI всё это запускается автоматически; никакие live LLM/mail/Telegram/webhook вызовы не разрешены.

FeatureRegistry автоматически подключает task feature config/routes, migrations, translations, commands, explicit feature seeds, permission/adapter keys. Контракт в docs/contracts/loaders.md; test discovery охватывает tests/**/*Test.php, Vitest tests/**/*.test.ts(x), Playwright tests/Browser/**/*.spec.ts. Donor baseline tools имеют отдельный runner и не запускаются как приложение.

users.id и sessions.user_id — ULID. Нативные framework таблицы password_reset_tokens, sessions, cache/cache_locks, jobs/job_batches/failed_jobs сохраняют необходимые Laravel имена/ключи, они не domain entities. DatabaseSeeder пуст: явные локальные feature seeds вызываются командой registry, не на boot.

PreviewProps анонимны и допустимы только в local/testing preview/dev. Настоящие tenant/auth/policy/shared props принадлежат последующим задачам; SSR по умолчанию выключен; SSR и общий production i18n helper отложены принятым контрактом. Минимальный preview и будущие макеты используют Tailwind utilities; CSS остаётся для токенов, общих правил и декораций. После БД и первых макетов обязательно остановиться для пользовательского просмотра.

## Основной сайт после интеграции

Laravel и зависимости установлены непосредственно в `C:/OSPanel/home/souzpir.local`; React/TypeScript находятся в `resources/js`, CSS — в `resources/css`. OSPanel направляет souzpir.local на `public`, PHP 8.4.19. PostgreSQL доступна, bootstrap и E0-02 миграции применены, явный local seed создаёт одну организацию и три демонстрационных бренда без пользователей. Тесты используют отдельную БД и runtime.

Для временного CLI HTTP-сервера использовать `artisan serve --env=testing --no-reload` с индивидуальным портом и тестовым окружением: `--no-reload` сохраняет переданные процессу параметры. Ранний dotenv loader использует Laravel Env repository; переменные процесса имеют приоритет над значениями файлов. Проверено локально и в GitHub Actions, включая настоящий Chromium.
