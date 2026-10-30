# 03b. Таблица переноса функций ИИ-продавца и общих сервисов

Колонки: функция · где реализовано сейчас (файл/класс::метод) · вход/выход · зависимости и конфиг · настраивается per компания/бренд в ТЗ · куда переезжает · режим переноса (**сохранить** / **переработать** / **новое**) · как доказать эквивалентность.

## 1. Движок диалога (прототип → `App\Seller\Engine`)

| Функция | Сейчас | Вход → выход | Зависимости/конфиг | Per бренд (ТЗ) | Куда | Режим | Доказательство |
|---|---|---|---|---|---|---|---|
| Приём хода | `Orchestrator::turn` | Lead, text, ctx → Message(assistant) + обновлённый Lead | Extractor, Policy, Responder, Guard, Summarizer, `config/agent.php` | режим бот/суфлёр/менеджер (А.11, D-9.x) | `Engine\Orchestrator::turn(Conversation, InboundMessage, BrandRuntime)` | сохранить (замена источников зависимостей) | TurnTest (4) + фикстуры сценариев |
| Бот первым | `Orchestrator::open` | Lead → Message | persona.intro | приветствия D-6.2 | `Engine\Orchestrator::open` | сохранить; приветствие по каналу — надстройка | тест open с фейком LLM |
| Извлечение | `Extractor::extract`, `prompts/extractor.blade.php` | state, history → slots/signals/client_type | модель extractor, t=0, `history_turns_for_llm` | нет (C — только чтение D-5.1) | `Engine\Extractor` + промпт в `prompt_sets` (версионируемый) | сохранить дословно | снапшот промпта: отрендеренный текст совпадает с эталоном на фиксированном state |
| Состояние лида | `LeadState` (`SLOTS`, `STAGES`, `OUTCOMES`, `apply`, счётчики) | extraction → state | — | — | `Engine\LeadState` | сохранить | PolicyTest + unit на apply |
| Правила/решение | `Policy::decide` и приватные методы | state, signals, knowledge → Goal | `policy.yaml` (slot_order, conditional_slots, limits, tools.always, client_type_rules, followup, outcomes), `escalation.yaml`, `triggers.yaml`, `closed_topics.yaml` | D-5.2 (порядок слотов, лимиты), D-5.3 (причины), D-5.4 (приёмы), D-5.6 (вне темы/негатив) | `Engine\Policy` читает `KnowledgeSnapshot` | сохранить; новые настройки — с дефолтом = YAML | PolicyTest (11) + табличные тесты «state → Goal» из фикстур |
| Цель реплики | `Goal` | — | — | — | `Engine\Goal` | сохранить | — |
| Генерация | `Responder::reply`, `prompts/responder.blade.php` | Goal, state, history, knowledge → text + tool_calls | модель responder t=0.7, fewshot limit 6 / minConf 0.7 | D-6.1 персона, D-6.3 фразы | `Engine\Responder` | сохранить; доп. блоки промпта (приветствия, каталог) — только при включённых настройках | снапшот промпта; с фейком LLM — проверка, что tool_calls выполняются |
| Инструменты | `ToolRunner::*` (9 tools) | tool call → result/side effects (Tasks, Lead) | `policy.yaml tools.always`, `TELEGRAM_MANAGERS_CHAT`, зашитые данные | D-5.7 | `Engine\Tools\*` по одному классу на tool, данные — из `brand_seller_settings` | сохранить логику; данные → настройки (дефолт = зашитые) | unit per tool: с дефолтными настройками результат = прежний |
| Guard | `Guard::check/ngramOverlap/badNumbers/filledSlotQuestion/llmCheck` | text, Goal, state, knowledge → issues[] | `guard_rules.yaml` 22, `persona.limits`, `conditions.max_pct_unapproved`, модель guard | D-4.9 (правила, strictness), D-6.1 лимиты | `Engine\Guard` | сохранить; режим warn — новое поле `severity` (дефолт block) | GuardTest (13) + новые тесты warn |
| Регенерация/фолбэк | `Orchestrator` (≤1 регенерация, `safeFallback`, `handoffOnError`) | — | `policy.yaml fallback_reply` | D-6.3 | `Engine\Orchestrator` | сохранить | TurnTest сценарии guard_rejected / error |
| Сводка | `Summarizer`, `summary.blade.php` | history → summary | `summary_every_turns` 6 | — | `Engine\Summarizer` | сохранить | unit: вызывается на 6-м ходу |
| Знания (чтение) | `Knowledge::all/conditions/persona/policy/guardRules/escalation/intents/fact/factsForIntent/trigger/triggers/closedTopics/fewshot/badExamples/suggestedQuestion/resolvePlaceholders/formatCond` | ключ → данные | YAML, кэш | D-4.x | `Engine\KnowledgeSnapshot` (тот же интерфейс, данные из релиза) | сохранить интерфейс | `KnowledgeEquivalenceTest`: массив из БД == массив из YAML |
| Знания (хранение) | YAML-файлы, `agent:knowledge:reload` | — | — | D-4.1–D-4.12 | таблицы `knowledge_*` + `knowledge_releases` (04) | новое | импорт YAML → релиз → экспорт YAML == исходный (нормализованный) |
| Напоминания | `FollowupsSend::handle` | cron → отправка | `policy.followup`, fewshot `followup_nudge`, зашитый текст | D-5.5 | `Jobs\SendFollowups` per бренд | переработать (два интервала, окно, текст из настроек) | тест «старое поведение» при `second_interval = null`; тест нового |
| Передача менеджеру | `ToolRunner::escalate` + `escalation.yaml` | reason → Task + Telegram | `after_handoff` глобальный | D-5.3 | `Engine\Tools\Escalate` + `handoff_reasons` per бренд | сохранить; per-reason after — новое | unit per reason |
| Суфлёр | `config('agent.suggest_mode')` глобально | — | env | D-9.1 режим per канал (Автономно / Подсказки / Выключен), D-2.2 режим per диалог | `conversations.mode` + `channel.default_mode` | переработать | TurnTest: в режиме suggest сообщение не доставляется, создаётся подсказка |
| Telegram входящий | `TelegramWebhookController` | update → turn | `TELEGRAM_BOT_TOKEN` | D-9.5 | eyon `TelegramInbound` + `channel_accounts` | переработать (берём из eyon) | feature test webhook с секретом |
| Супервизор | `Supervisor` (5 слоёв, 24 типа) | диалоги периода → отчёт | модель judge | D-7.2 | `Engine\Supervisor` + Job | сохранить | SupervisorTest (3) |
| Симулятор | `AgentSimulate` + `tools/personas.yaml` + `simulator.blade.php` | persona → диалог | модель simulator | D-7.4–D-7.6 | `Engine\Simulator` + таблицы `seller_personas`, `seller_sim_runs` | сохранить | прогон с фейком: число ходов и теги совпадают |
| Судья | `AgentJudge` + `judge.blade.php` | диалог, expect[] → оценки | модель judge | D-7.7, D-7.8 | `Engine\Judge` | сохранить; калибровки — новое | фейк LLM + проверка `expect` |
| Ревью реплик | `review` + API | message_id, type, note | — | D-2.5 | `message_reviews` | сохранить | feature test |
| Задачи | `tasks` + API | — | — | D-3.3, D-3.2 вкладка «Задачи и обещания» | `seller_tasks` | сохранить | feature test |
| Стоимость | `llm_calls.cost_usd` | — | OpenRouter usage | D-7.1 KPI, S-4.2, D-11.3 (в единицах тарифа — Q-02) | eyon `ai_calls` + `object_costs` | переработать (общий шлюз) | тест: каждая стадия turn пишет вызов с `purpose` |

## 2. Общие сервисы (eyon-pilot → новый бэкенд)

| Функция | Сейчас | Куда | Режим | Доказательство |
|---|---|---|---|---|
| Контекст организации/бренда | `TenantContext`, `ResolveBrand`, `memberships.role` + `brand_ids` | `App\Tenancy\{TenantContext, ResolveTenant}` | сохранить (роли и `brand_ids` = А.12); добавить флаг «менеджер продаж» (B) | тесты изоляции: запрос бренда чужой организации → 404; матрица ролей S-3.3 |
| Приглашения | `invitations` | `invitations` (роль + бренды) | сохранить | feature test S-3.4 / S-0.5 |
| Журнал действий | `audit_logs` | `audit_logs` | сохранить | тест: каждое изменение настроек пишет запись |
| Домены | `WidgetController` verify | `Shell\Domains` | сохранить | тест с фейком HTTP/DNS |
| Виджет | `widget_*`, `/widget.js` | `Seller\Widget` | сохранить | тест загрузчика |
| ИИ-шлюз | `AiGateway`, бюджеты, маскирование | `App\Ai\*` | сохранить | тест маскирования контактов и лимита бюджета |
| API-токен | `ApiToken` middleware, один `AGENT_API_TOKEN` (прототип) | Sanctum-токены бренда с abilities (S-5.2) | переработать | feature: права read/turn/leads |
| Экспорт данных | `seller-export` JSONL (eyon) | `data_exports` (S-7.4, D-3.5) | сохранить + CSV/XLSX | job пишет файл и SHA-256 |
| Каталог | `CatalogReader/Import/Lookup` | `Seller\Catalog` | сохранить | тесты парсера на фикстурах CSV/XLSX |
| Видение | `Visibility/*` | `App\Vision\*` | сохранить логику замера/отчёта; `QuizFlow`, `VisibilityPlan` — переработать | существующие тесты eyon (если есть в архиве — проверить при старте), снапшот отчёта |
| Аудит сайта | `SiteInspector` | `Vision\SiteAudit` | сохранить | фикстуры HTML |
| Уведомления | `Notifications/*` | `App\Notifications` | сохранить | тесты доставки |
