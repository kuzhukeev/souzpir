# 04. Архитектура Laravel-бэкенда

Приложения: `04b_data_model.md` (таблицы), `04c_settings_paths.md` (полный путь каждой настройки). Все решения — **рекомендации**, если не указано иное; факты о коде — со ссылками на `03`.

## 1. Стек и принципы

- Laravel 13 (PHP 8.4), PostgreSQL 16, Redis (очереди, кэш, rate limit), Horizon, Scheduler, Pest. Laravel 13 выпущен 17.03.2026 и поддерживает PHP 8.3–8.5 ([Laravel releases](https://laravel.com/docs/master/releases.md)).
- Один Laravel-монолит, модульная структура по доменам (`app/Domains/*` не используем — вместо этого namespace-ы по контекстам ниже), один фронтенд (05).
- Все бизнес-данные привязаны к `organization_id` и (где осмысленно) к `brand_id`. Глобальный scope на моделях + явная проверка в политиках.
- Никакие «будущие» интеграции (головная админка, WhatsApp, amoCRM, Bitrix24, 1С, Google Sheets) не являются обязательными для запуска; в коде — только контракты/интерфейсы с заглушками, помеченными как `planned`.

## 2. Домены и структура кода

```
app/
  Shell/        — Auth, Organizations, Brands, Memberships, Invitations, Roles, Domains, Themes, Notifications, Audit, Plans, Billing(payer), Integrations(ApiKeys, Webhooks), DataExports, Support(Tickets, AccessGrants), Help (S-0…S-8)
  Tenancy/      — TenantContext, ResolveTenant middleware, scopes, BrandSwitch
  Ai/           — AiGateway (из eyon), providers, budgets, cost attribution, masking, prompt_sets
  Vision/       — Quiz, QuestionSets, Measurements, Reports, Sources, SiteAudit, Actions, Schedules, Publications (V-0…V-11)
  Seller/
    Engine/     — Orchestrator, Extractor, LeadState, Policy, Goal, Responder, Guard, Summarizer, Supervisor, Simulator, Judge, Tools/*, KnowledgeSnapshot  (перенос прототипа)
    Knowledge/  — KnowledgeRepository, Releases, Import/Export YAML, Validation (D-4)
    Conversations/ — Conversation, Message, Review, Assignment, Takeover (D-2)
    Customers/  — Contacts, Merge (D-3.4), ImportExport (D-3.5), Tasks/Promises (D-3.3) (D-3)
    Channels/   — ChannelList/Modes/EmergencyStop (D-9.1), Telegram (из eyon), Widget (из eyon), ApiLeads+FormMapping (D-9.9), Email (D-9.8); VK/WhatsApp — planned (D-9)
    Catalog/    — из eyon (D-10.1, D-10.4)
    Integrations/ — CrmWebhook (D-10.2, общий слой с Shell/Integrations), Stock (D-10.4), planned-stubs (D-10.3)
    Analytics/  — Funnel, Costs, Managers, Exports (D-8)
    Settings/   — BrandSellerSettings (D-5, D-6, D-11)
  Http/         — Controllers (Inertia + JSON), Requests, Resources, Middleware
  Jobs/, Events/, Listeners/, Notifications/, Console/
```

Внутри `Seller\Engine` классы переносятся с минимальными правками (см. 03b). Правило: в `Engine` нет Eloquent-запросов к настройкам — всё приходит через `BrandRuntime` (DTO: snapshot знаний, настройки, провайдер).

## 3. Мультитенантность

- `organizations` → `brands` → все модульные данные. Текущая организация и бренд выбираются в UI (шапка), хранятся в сессии (`current_organization_id`, `current_brand_id`) и прокидываются `ResolveTenant` в `TenantContext`.
- URL без tenant-префикса (как в ТЗ: `/vision/...`, `/seller/...`); JSON API — те же маршруты под `/api/v1/...` с заголовком `X-Brand-Id` (для внешних интеграций — токен бренда).
- Глобальный scope `BelongsToTenant` фильтрует по `organization_id` (и `brand_id`, если колонка есть). Политики (`Policy` Laravel) проверяют членство и роль; доступ к бренду — через `memberships.brand_ids` (как в eyon; пустой список = все бренды для owner/admin).
- Переключение бренда инвалидирует фронтенд-кэш (05 §4).

## 4. Роли, права, приглашения, аудит

- Технические роли по ТЗ А.12 и eyon: `owner`, `admin`, `member`, `viewer`. Подписи интерфейса (ТЗ §0.7, S-3.3): Владелец, Администратор, Редактор, Менеджер, Наблюдатель — Редактор и Менеджер различаются флагом `memberships.settings.sales_manager` (B, R-S-16). Права — матрица S-3.3 в `config/permissions.php` (`seller.knowledge.write`, `vision.measure.run`, `org.billing`, …): Редактор — модули целиком без команды и интеграций; Менеджер — Диалоги, Клиенты, Очередь подсказок, отчёт Видения для чтения; Наблюдатель — чтение. Проверка через Gates; фронтенд получает `can[]` в shared props. Кастомные роли и доступ по модулям — C.
- Доступ `member`/`viewer` ограничивается `brand_ids`; owner/admin — все бренды.
- Приглашения (`invitations`: email, role, brand_ids, token, expires_at): S-3.3 → письмо → S-0.4a/b (регистрация/вход по приглашению). Повторная отправка и отзыв — S-3.1.
- Аудит: `audit_logs` (actor, organization, brand, action, subject, before/after JSON diff, ip) пишется из Observer/Action-классов для всех настроек и критичных действий (S-6.1). Ретенция — настройка платформы.
- MFA для клиентов (S-0.1, B): TOTP через `laravel/fortify`-совместимый подход, секреты шифруются.
- Доступ поддержки (S-8.2): `support_access_grants` с `expires_at` = 72 ч; режим `admin.support` eyon переносится и проверяет срок.

## 5. Настройки

Три уровня: платформа (`config/*` + таблица `platform_settings`), организация (`organizations.settings` JSON + отдельные таблицы), бренд (`brands.settings` + `brand_seller_settings`, `brand_vision_settings`). Каждая настройка описана в `04c_settings_paths.md` в формате: поле UI → валидация → endpoint/Action → хранение → применение → права → тест. Настройка не считается готовой без «применения» и теста.

## 6. Знания и промпты: версии, YAML → БД

### 6.1. Хранение
- `knowledge_releases` (brand_id, version, status draft/published/archived, created_by, published_at, parent_id, checksum).
- Детальные таблицы, по одной на YAML-структуру, каждая строка привязана к `release_id`: `kn_persona` (одна строка), `kn_policy` (одна строка JSON), `kn_facts` (атомарно: group_id, topic, text, sort; группа — `kn_fact_groups` с `must_not[]`, `bridge_hint`), `kn_conditions` (документ JSON по ключам conditions.yaml + per-условие `approved`), `kn_intents`, `kn_guard_rules` (+ `severity`), `kn_escalation_reasons` (+ `after` per reason), `kn_triggers` (+ `is_base`), `kn_fewshot`, `kn_closed_topics`.
- `KnowledgeSnapshot::fromRelease($release)` собирает массив с той же структурой, что `Knowledge::all()` прототипа (03 §3 п.4). Методы `Knowledge::*` переносятся без изменений поверх массива.
- Черновик = release `draft`, редактируется в UI; «Опубликовать» (D-4.12) создаёт неизменяемую копию, активный релиз хранится в `brands.active_knowledge_release_id`; откат — смена указателя. Песочница (D-4.11) принимает `release_id`.
- Импорт YAML (команда `seller:knowledge:import --brand --dir`) → валидация JSON-схемами (`resources/schemas/knowledge/*.json`) → релиз. Экспорт — обратная операция; тест «импорт → экспорт» даёт нормализованно равный YAML.

### 6.2. Промпты
- `prompt_sets` (из eyon): name (extractor/responder/guard/summary/simulator/judge), version, body (Blade), status, brand_id nullable (платформенные по умолчанию). Движок рендерит Blade-строку из БД через `Blade::render`. Правка промптов — только роль платформы (не в кабинете клиента в v1); в кабинете — просмотр версии (D-7.x).

## 7. API, валидация, ошибки

- Веб-слой: Inertia-контроллеры возвращают страницы; все мутации — `FormRequest` + Action-класс; ответ — redirect с flash или JSON для `useHttp`.
- JSON API `/api/v1` (Sanctum-токены бренда из S-5.2 с abilities `read` / `turn` / `leads`; заменяют единый `AGENT_API_TOKEN` прототипа): `POST /leads` (D-9.9, с маппингом полей), `POST /turn` (совместимость с прототипом), `GET /conversations`, `GET /tasks`, `webhooks/telegram/{channel_account}`, `widget/*`. Версионирование в пути.
- Ошибки: единый формат `{message, errors{}, code}`; доменные исключения → 422/409; 404 для чужого tenant (не 403, чтобы не раскрывать существование).
- Идемпотентность внешних входов: `Idempotency-Key` для `POST /leads`, `update_id` Telegram в `channel_inbound_events` (unique).

## 8. Очереди, планировщик, повторы

- Очереди: `seller-turns` (ответы бота; один воркер на диалог через `WithoutOverlapping(conversation_id)`), `ai` (прочие LLM), `vision` (замеры, длительные), `notifications`, `default`.
- Повторы: LLM-вызовы — 3 попытки с backoff в `AiGateway`; при итоговой ошибке turn → `handoffOnError` (как в прототипе). Вебхуки исходящие — 5 попыток, экспоненциально, журнал `webhook_deliveries`.
- Планировщик: `seller:followups` (каждые 10 мин, per бренд, с окном отправки), `seller:handoff-timeouts` (D-5.3 «никто не взял»), `seller:email-reminders` (D-10.3 B — напоминания о договорённостях на почту менеджеру), `vision:measurements` (по `monitoring_schedules`), `vision:report-publications-expire`, `ai:budget-reset`, `audit:prune`, `data:purge` (S-7.4 отложенное удаление через 14 дней), `support:expire-grants` (S-8.2).
- Все jobs — tenant-aware (принимают `brand_id`, восстанавливают `TenantContext`).

## 9. События и уведомления

Доменные события: `LeadCreated`, `ContactCaptured`, `ConversationHandedOff`, `TaskCreated`, `PromiseDue`, `UnknownQuestionLogged`, `MeasurementCompleted`, `ReportPublished`, `BudgetThresholdReached`, `MemberInvited`, … Слушатели → `cabinet_notifications` (колокольчик S-1.3) + каналы по `notification_settings` (личные S-7.1, общие компании S-5.3; email, Telegram) + исходящие вебхуки (S-5.2/D-10.2). История доставок — S-6.2. Матрица событий — словарь (R-S-09).

## 10. ИИ-провайдеры, расходы, наблюдаемость

- `AiGateway` (из eyon) — единая точка: выбор провайдера по `purpose` и настройкам бренда, маскирование контактов (`AiContactMasker`) до отправки, лимиты (`AiBudget`), запись `ai_calls` (purpose, model, tokens, cost_usd, latency, brand_id, object_type/id). Движок продавца пишет `purpose` = extractor/responder/guard/summary/judge/simulator — как `llm_calls.purpose` прототипа.
- Пересчёт в «единицы тарифа» для клиента — через коэффициент в `plans` (открытый вопрос R-D-25); до решения UI показывает только количество вызовов/токенов.
- Логи — структурированные (JSON) с `conversation_id`, `turn_id`, `brand_id`; метрики — счётчики guard_rejected, fallback, latency p95 (экспорт в Prometheus — опционально).

## 11. Файлы

Диск `s3`-совместимый (или local в dev): логотипы брендов, импорты каталога, вложения знаний, экспорты CSV/PDF (D-8.6, B), публичные отчёты. Приватные файлы — через подписанные URL.

## 12. Будущая головная админка (раздел 8 ТЗ)

- v1 полностью автономна: регистрация, организации, пользователи, тарифы — локально.
- Граница: все сущности имеют внутренний `id` (ULID) и таблицу `external_identities` (entity_type, entity_id, system, external_id). Контракты будущей синхронизации описаны как интерфейсы `Shell\Contracts\IdentityProvider`, `Shell\Contracts\PlanProvider` с локальной реализацией; никаких HTTP-вызовов к несуществующему API. Правила конфликтов при будущей синхронизации — «источник истины внешний для идентичности и тарифов, внутренний — для модульных данных» — фиксируются как решение, не реализуются.

## 13. Миграции

Нумерация по доменам, отдельные файлы на таблицу; сидер эталонного релиза знаний из YAML прототипа (для тестов и демо); сидер `plans` без цен (R-S-05).
