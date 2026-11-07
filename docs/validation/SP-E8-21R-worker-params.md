# SP-E8-21R — проверка параметров аудита

## Что проверено (fake runtime)

- `AuditSiteJob`: `timeout = 75`, `tries = 1`. `config('queue.connections.database.retry_after')` = 90 (из `.env.example`/дефолта `config/queue.php`, переменная `DB_QUEUE_RETRY_AFTER` не переопределена) — больше таймаута задания, повторный запуск воркером во время выполнения не произойдёт. Подтверждено `SiteTimingE821QTest`.
- Права: viewer получает 403 на `POST /vision/site/audit` (`AuditQueueE812CTest`), owner/admin/editor разрешены, manager/viewer запрещены (`SiteWorkflowTest`).
- Изоляция брендов: прогресс и очередь ключуются per-brand (`AuditSiteJob::key`), чужой бренд видит `auditProgress: null` (`AuditQueueE812CTest`, `SiteProgressE821ETest`, `SiteElapsedE821PTest`).
- Ошибки: некорректный URL (с query/credentials) отклоняется до постановки в очередь; отзыв сессии/membership во время выполнения задания останавливает запись без создания `audit_runs`; исключение инспектора переводит задание в `failed` без создания аудита (`AuditQueueE812CTest`, `SessionModuleTest`, `SiteWorkflowTest`).
- Прогресс никогда не публикует оценку оставшегося времени (`remaining_seconds: null` всегда) — подтверждено `SiteTimingE821QTest`, `SiteTimingE821OTest`.

Поведение не менялось, новый код не добавлялся — существующие тесты (39, 503 проверки) уже покрывают эти параметры по итогам SP-E8-21Q.

## Не проверено

Фактические параметры развёрнутого воркера (флаги запуска `queue:work`, реальная длительность на живом сайте) — требуют доступа к окружению и отдельного разрешения владельца на внешний запрос; в репозитории конфигурации деплоя воркера нет. Внешние запросы не выполнялись.
