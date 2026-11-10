# Очереди и планировщик

Описывает архитектуру и повседневную работу с очередями/расписаниями в этом checkout. Производственная выкладка systemd-юнитов (`souzpir-queue`, `souzpir-schedule`) и включение живых каналов — в [docs/release/prod-runbook.md](../release/prod-runbook.md). Изоляция worktree (свои `QUEUE_NAMESPACE`/БД/порты, запрет на реальные отправки в тестах) — в [docs/environment.md](../environment.md).

## Именованные очереди

`config('runtime.queues')` даёт пять логических очередей, каждая с префиксом `QUEUE_NAMESPACE` (по умолчанию равен `APP_INSTANCE`, уникален на worktree):

`seller-turns`, `vision`, `ai`, `notifications`, `default`.

На практике явный `onQueue()` сейчас использует только `notifications` (доставка писем верификации/сброса пароля/смены email, см. `app/Shell/Auth/**/Jobs`, `app/Shell/Profile`). Остальные job-классы с `ShouldQueue` идут в `default` очередь выбранного соединения; разделение по `seller-turns`/`vision`/`ai` — для будущих job, которые явно укажут `onQueue(config('runtime.queues.<name>'))`.

Основные job-классы по доменам:

- **Seller**: `Dialogs/DeliverManagerMessage`, `Followups/{Send,Process,Prepare}Followups`, `Live/LiveDraftJob`, `Notifications/NotifyConversationEvent`, `Scenarios/RunScenarioGroup`, `Tasks/NotifyDialogEvent`, `Evaluation/Jobs/{Judge,Simulate}FakeJob`.
- **Shell**: `Integrations/Webhooks/{DeliverWebhook,DeliverFakeWebhook,ProcessFakeWebhookEvent}`, `Integrations/CompanyNotifications/{Deliver,DeliverFake}CompanyNotification`, `Notifications/Jobs/{DeliverNotification,ProcessNotificationEvent}`, `Auth/**/Jobs/Deliver{Password,Verification}`, `Profile/DeliverEmailChange`, `Profile/TestNotifications/DeliverTestNotification`.
- **Vision**: `Competitors/Jobs/RecalculateMergedReportJob`, `Measurements/RunMeasurement`, `Site/Jobs/AuditSiteJob`.

## Соединение очереди

- Dev/preview и тесты: `QUEUE_CONNECTION=sync` — задания выполняются сразу в том же процессе, отдельный worker не нужен.
- Прод (`.env.production.example`): `QUEUE_CONNECTION=database`, таблица `jobs`; worker — systemd `souzpir-queue` (`php artisan queue:work database --queue=souzpir-main:notifications,souzpir-main:default --sleep=3 --tries=3`; при другом `QUEUE_NAMESPACE` поменять префикс в unit-файле).
- Для ручной проверки прохождения задания через настоящую БД-очередь в своём worktree: временно выставить `QUEUE_CONNECTION=database` в `.env`, выполнить `php artisan queue:work database --once`, затем вернуть `sync`. Постоянный worker в рамках разработки не запускается.

## Планировщик

Расписания объявлены в `routes/console.php` и целиком отключены по умолчанию флагом `FEATURES_SCHEDULING_ENABLED=false`:

- `seller-evaluation-supervisor` — `DailySupervisor::run()`, раз в день, таймзона `Europe/Moscow`.
- `vision-measurements` — `MeasurementSchedule::dispatchDue()`, раз в минуту; дополнительно требует `VISION_MEASUREMENT_SCHEDULE_ENABLED=true` (`config('eyon.measurement_schedule_enabled')`).

`FeatureRegistry` (`app/Providers/Features/FeatureRegistry.php`) при каждом резолве `Schedule` также проходит по `schedules` контрибьюторам доменных feature-конфигов (контракт — [docs/contracts/loaders.md](../contracts/loaders.md)) под тем же флагом `FEATURES_SCHEDULING_ENABLED`; сейчас ни один feature так расписание не регистрирует. Новой задаче с собственным расписанием нужно добавить класс `ScheduleContributor::register(Schedule $schedule)` и указать его в `schedules` своего feature-конфига — не редактировать `routes/console.php` напрямую (общий файл, владелец — интегратор).

Прод включает `FEATURES_SCHEDULING_ENABLED=true` и запускает `php artisan schedule:work` через systemd `souzpir-schedule`. В dev/testing для разового прогона — `php artisan schedule:run --no-interaction` при временно включённом флаге; постоянный планировщик в рамках разработки не запускается.
