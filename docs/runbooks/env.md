# Перечень env

Точный список ключей — в `.env.example` (dev) и `.env.production.example` (прод, покрывает все `env()` из `config/*.php` верхнего уровня — проверяется `tests/Feature/Launch/L1-08/ProductionEnvExampleTest.php`). Здесь — обзор по назначению, без повторения значений, чтобы не расходиться с кодом.

## Framework (стандартные ключи Laravel)

`APP_*`, `LOG_*`, `DB_*`, `SESSION_*`, `CACHE_*`, `REDIS_*`, `QUEUE_*` (кроме `QUEUE_NAMESPACE`), `MAIL_*`, `AUTH_*`, `FILESYSTEM_DISK`, `AWS_*`, `MEMCACHED_*`, `BROADCAST_CONNECTION`, `INERTIA_*`, `VITE_*`, `TRUSTED_PROXIES` — стандартная конфигурация `laravel/laravel`, описана в документации Laravel/Inertia. Project-specific из этой группы: `APP_INSTANCE` (уникален на worktree, формирует `CACHE_PREFIX`/`REDIS_PREFIX`/`QUEUE_NAMESPACE` по умолчанию), `APP_PREVIEW_DATABASE`, `DB_TEST_DATABASE` (изоляция worktree, см. [docs/environment.md](../environment.md)), `TRUSTED_PROXIES` (см. [https-forwarding.md](https-forwarding.md)).

## Домен/фича

| Префикс | Назначение |
|---|---|
| `CABINET_ORGANIZATION_ID` | ULID единственной организации инсталляции (SP-E0-02). |
| `AI_*` (`AI_DRIVER`, `AI_LIVE_ENABLED`, `AI_BUDGET_*`, `AI_RECOVERY_OPERATOR_USER_ID`, `OPENROUTER_API_KEY`) | Шлюз к LLM: драйвер (`fake` по умолчанию), бюджеты расходов, оператор восстановления после сбоя. |
| `LIVE_DRAFT_*` | Живой черновик ответа продавца по Telegram-сценарию (LV-01); `LIVE_DRAFT_ENABLED` — боевой флаг, включает только владелец. |
| `TELEGRAM_*`, `NOTIFICATIONS_TELEGRAM_BOT_TOKEN` | Канал продавца и менеджерских уведомлений в Telegram; `TELEGRAM_LIVE_ENABLED` — боевой флаг. |
| `VISION_*` | Отчёты видимости бренда: `VISION_LIVE_MEASUREMENTS_ENABLED` (боевой флаг), `VISION_MEASUREMENT_QUEUE_ENABLED`, `VISION_MEASUREMENT_SCHEDULE_ENABLED`, `VISION_EVIDENCE_PARSER_MODEL` (см. [queues-scheduler.md](queues-scheduler.md)). |
| `SITE_AUDIT_FETCH_ENABLED` | Внешние запросы аудита сайта бренда. |
| `DELIVERY_DRIVER`, `NOTIFICATION_DRIVER` | Общие fake/реальные драйверы доставки писем и уведомлений. |
| `COMPANY_NOTIFICATION_DELIVERY_ENABLED`, `INTEGRATIONS_WEBHOOK_DELIVERY_ENABLED`, `INTEGRATIONS_SINGLE_NODE_LOCKS` | Вебхуки и уведомления компании (`app/Shell/Integrations`). |
| `SELLER_DIGEST_ENABLED`, `SELLER_NOTIFICATIONS_ENABLED`, `SELLER_NOTIFICATION_DEDUP_MINUTES` | Дайджесты и уведомления по диалогам продавца. |
| `PROFILE_SETTINGS_ENABLED`, `PROFILE_EMAIL_CHANGE_ENABLED`, `PROFILE_TELEGRAM_BOT_USERNAME`, `PROFILE_TELEGRAM_WEBHOOK_SECRET` | Настройки профиля пользователя и его Telegram-привязка. |
| `AUTH_BOOTSTRAP_TOKEN_HASH`, `INVITATION_ISSUANCE_HMAC_KEY` | Первичный бутстрап-доступ и подпись приглашений (секреты, не логировать). |
| `BRAND_EXTERNAL_CHECKS_ENABLED` | Внешние проверки бренда при сохранении настроек. |
| `DATA_EXPORT_TTL_MINUTES`, `DATA_EXPORT_ORPHAN_GRACE_MINUTES` | TTL публичных экспортов отчётов и их отзыв. |
| `SUPPORT_*`, `SLACK_BOT_USER_*` | Канал обращений в поддержку. |
| `FEATURES_SCHEDULING_ENABLED` | Общий выключатель планировщика (см. [queues-scheduler.md](queues-scheduler.md)). |

Все боевые/внешние флаги по умолчанию выключены (`false`/`fake`) и в тестах не включаются (см. [docs/environment.md](../environment.md), раздел «Изоляция каждого worktree»); включение — отдельное решение владельца, описано в [prod-runbook.md](../release/prod-runbook.md#включение-живых-флагов).

Техдолг: `ProductionEnvExampleTest` сверяет `.env.production.example` только с `config/*.php` верхнего уровня — ключи из `config/features/*.php` (большинство строк из этой таблицы) в тесте не проверяются и могут отсутствовать в шаблоне прод-`.env`; владелец файла — SP-L1-08.
