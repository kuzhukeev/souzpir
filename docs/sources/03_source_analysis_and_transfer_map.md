# 03. Анализ исходных проектов и карта переноса

Таблица переноса по функциям — `03b_transfer_table.md`. Здесь — проверенные факты о реализации (с путями к файлам), оценка полноты и решения «перенести / переработать / новое».

Обозначения статуса фактов: **[факт]** — подтверждено чтением кода; **[вывод]** — следует из кода, но не проверялось запуском; **[рекомендация]**.

## 1. Прототип ИИ-продавца «Союз Пир» (`src/Prototip-II-prodavtsa-Soiuz-Pir-Laravel-1-3/agent/`)

### 1.1. Состав

- `app/Agent/`: `Orchestrator.php`, `Extractor.php`, `Policy.php` (802 строки), `Goal.php`, `Responder.php`, `Guard.php`, `Knowledge.php`, `LeadState.php`, `Summarizer.php`, `Supervisor.php`, `Tools/ToolRunner.php`, `Llm/OpenRouterClient.php` (+ `LlmCall` логирование). [факт]
- Знания: `resources/knowledge/{persona,policy,facts,conditions,intents,guard_rules,escalation,triggers,fewshot,closed_topics}.yaml` (10 файлов); `Knowledge::load()` читает их все в память и кэширует; `php artisan agent:knowledge:reload`. [факт]
- Промпты: `resources/views/prompts/{extractor,responder,guard,judge,simulator,summary}.blade.php`. [факт]
- Команды: `AgentChat`, `AgentJudge`, `AgentSimulate` (`--persona --run --max-turns`, результаты в `storage/app/sim/{run}.json`), `AgentSupervise`, `FollowupsSend`, `KnowledgeReload`. [факт]
- HTTP: `routes/api.php` — `POST /webhooks/telegram` (без токена), под middleware `ApiToken`: `POST /turn`, `GET /leads`, `GET /leads/{lead}`, `POST /leads/{lead}/handoff`, `POST /leads/{lead}/reset`, `POST|GET /review`, `PATCH /review/{review}`, `GET /tasks`, `PATCH /tasks/{task}`. Эндпоинта создания лида снаружи (`POST /leads`) нет. [факт]
- Таблицы: `leads` (state JSON, stage, outcome, client_type, do_not_disturb, followups_sent, next_followup_at, summary, …), `messages` (role, text, meta JSON), `llm_calls` (purpose, model, tokens, cost_usd, latency), `tasks` (type, payload, due_at, status), `review` (message_id, type, note), `evaluations`. [факт]
- Тесты: `tests/Feature/{SupervisorTest (3), TurnTest (4)}`, `tests/Unit/{GuardTest (13), PolicyTest (11)}` — 31 тест. LLM-вызовы в тестах подменяются фейком клиента (`TurnTest` использует `Http::fake`/мок `OpenRouterClient`). [факт по чтению; запуска не было]
- Модели (`config/agent.php`): extractor `google/gemini-2.5-flash` (t=0), responder `anthropic/claude-sonnet-5.5` (t=0.7), guard `gemini-2.5-flash` (t=0), summary `gemini-2.5-flash`, simulator `claude-haiku-5.5`, judge `claude-sonnet-5.5`. Все через OpenRouter. [факт]

### 1.2. Полный путь сообщения (`Orchestrator::turn`)

1. Вход: `TurnController` (API) или `TelegramWebhookController` → `Orchestrator::turn(Lead $lead, string $text, array $ctx)`. Лид находится/создаётся по `channel + external_id`. [факт]
2. **Extractor** (`Extractor::extract`): промпт `extractor.blade.php` с текущим `LeadState`, последними N сообщениями (`history_turns_for_llm` = 12) и списком слотов; возвращает JSON `{slots{}, signals{intent, sentiment, objections[], off_topic, prompt_injection, opt_out, wants_human, asks_price, …}, client_type, confidence}`. Нормализация значений слотов описана прямо в промпте (телефон, город, бюджет → число). [факт]
3. **LeadState** (`LeadState::apply`): слияние извлечённых слотов (только непустые, с порогом уверенности), счётчики `budget_asks`, `off_topic_streak`, `negative_streak`, `unknown_fact_insists`, `turns`. 16 слотов `SLOTS`; стадии `STAGES` = open/qualify/need/budget/close_contact/followup/handoff/lost/done/spam; исходы `OUTCOMES` = full_contact/partial_contact/handoff/paused/declined/stale/spam. [факт]
4. **Policy** (`Policy::decide`) — ядро правил, порядок проверок (по коду, сверху вниз): opt_out → `do_not_disturb` + тег `opt_out_ack`; prompt_injection → `decline_injection`; off_topic (streak 1 → `decline_off_topic`, 2 → `decline_off_topic_second`, ≥ `off_topic_streak_silent`=3 → исход spam/молчание); `negative_streak ≥ 2` → escalate `negative_sentiment` (`Policy.php:677`); wants_human → escalate `want_human`; причины из `escalation.yaml` (`deferred_insist`, `big_order` по `escalation_amount_rub`, `defect_claim`, `container_import`, `premises_approval`, `unknown_fact_insist`); затем выбор следующего слота по `slot_order[client_type]` с учётом `conditional_slots` и лимита `budget_asks_max` = 2; выбор приёма из `triggers.yaml` (`when/not_when`, `max_uses_per_dialog`, `Policy.php:585`); переход стадий; `max_turns_before_force_close` = 14. Результат — `Goal` (цель реплики: тип, слот, приём, теги few-shot, разрешённые tools, нужна ли передача). [факт]
5. **Responder** (`Responder::reply`): промпт `responder.blade.php` собирает persona (tone, intro, form_bans, limits), факты по интенту (`Knowledge::factsForIntent`), условия (`Knowledge::formatCond`, плейсхолдеры `{cond:path}` через `resolvePlaceholders`), few-shot (`Knowledge::fewshot(tags, clientType, limit 6, minConf 0.7)`), bad examples, цель, историю, summary. Поддерживает tool-calls (OpenRouter function calling) → `ToolRunner`; результаты tools возвращаются в модель для финального текста. [факт]
6. **Guard** (`Guard::check`): детерминированные проверки по `guard_rules.yaml` (22 правила, см. 02c R-D-08) + `ngramOverlap` (копипаст из фактов), `badNumbers` (числа, которых нет в условиях), `filledSlotQuestion` (вопрос о заполненном слоте), `llmCheck` (промпт `guard.blade.php`). При нарушении — ≤ 1 регенерация с замечаниями, затем `safeFallback` (`policy.yaml fallback_reply`) и, при ошибке LLM, `handoffOnError`. [факт]
7. Сохранение: `Message{role: assistant, text, meta{extraction, goal (включая trigger.id), tool_calls[], guard, guard_rejected, guard_fallback, error, latency_ms, cost_usd}}`; обновление `Lead` (state, stage, outcome, client_type, next_followup_at). Каждый вызов LLM — строка `llm_calls`. [факт]
8. **Summarizer** каждые `summary_every_turns` = 6 ходов обновляет `leads.summary` (промпт `summary.blade.php`). [факт]
9. `Orchestrator::open(Lead)` — реплика бота первым (для сценария «пришла заявка»). [факт]

### 1.3. Tools (`Tools/ToolRunner.php`)

| Tool | Реализация | Полнота |
|---|---|---|
| `escalate(reason, note)` | Lead → stage `handoff`, Task `handoff` с `message_idea` из `escalation.yaml`, уведомление в Telegram-чат менеджеров (`TELEGRAM_MANAGERS_CHAT`) | работает |
| `mark_do_not_disturb` | `leads.do_not_disturb = true`, стадия lost/declined | работает |
| `create_promise(text, due)` | Task `promise` с `due_at` (парсинг `parseWhen`: «завтра», «через N часов», дата) | работает |
| `log_unknown_question(q)` | Task `unknown_question` | работает |
| `send_requisites` | возвращает зашитый текст реквизитов «ООО „Союз Пир“ (черновик)» | текст зашит |
| `check_stock(sku)` | заглушка: `log_unknown_question` + `create_promise` + Task менеджеру (due 4 ч); остатков не знает | заглушка |
| `calc_delivery(city, weight)` | зашитая таблица из 9 регионов (regex по городу → цена/дни), перевозчик «КИТ»; иначе — «уточним» | данные зашиты |
| `send_price(email)` | создаёт Task `send_price` и пишет Log; письма не отправляет | заглушка |
| `set_followup(when)` | `leads.next_followup_at` | работает |

### 1.4. Напоминания (`Console/Commands/FollowupsSend.php`)

[факт] Выбирает лиды с `next_followup_at <= now()` или без ответа клиента дольше `nudge_hours` (20) × (`followups_sent` + 1); исключает стадии handoff/lost/done/spam и `do_not_disturb`; максимум `max_auto` = 2 авто-напоминаний; текст — few-shot с тегом `followup_nudge` по кругу; для «договорённого» контакта текст зашит в коде (строка 68, имя «Антон из „Союз Пир“»). Окна отправки (рабочие часы) нет. Отправка — через Telegram по `lead.channel`. Расписание — `routes/console.php` ежечасно. [факт]

### 1.5. Супервизор, симулятор, судья

- `Supervisor` (`AgentSupervise`): анализирует диалоги за период, `LAYERS` = 5 (extractor, policy, responder, guard, knowledge), `ISSUE_TYPES` = 24 (например `missed_slot`, `wrong_stage`, `fact_hallucination`, `tone`, …); результат сохраняется в `evaluations` (type=supervisor). [факт]
- `AgentSimulate`: персона из `tools/personas.yaml` (29 персон: id, name, type newbie/experienced/for_self/prices_only/troll/edge, facts, behavior, expect[], max_turns); симулятор-клиент — промпт `simulator.blade.php`; диалог гоняется через `Orchestrator::turn`; результат JSON `storage/app/sim/{run}.json`. [факт]
- `AgentJudge`: промпт `judge.blade.php`, оценки naturalness/goal/facts/objections/brevity (1–5) + проверка `expect[]`; запись в `evaluations`. [факт]

### 1.6. Суфлёр-режим

[факт] `config('agent.suggest_mode')` (env `AGENT_SUGGEST_MODE`) — глобальный флаг: при включении ответ не отправляется клиенту, а публикуется менеджерам как подсказка (Telegram-чат, Task `suggest`). Per бренд/канал режима нет. Это соответствует А.11 ТЗ «Режимы: бот / суфлёр / менеджер» лишь частично — режим «менеджер» (полное отключение бота по диалогу) есть только через handoff.

### 1.7. Что в прототипе неполно или отсутствует (важно для ТЗ)

- Один бренд/компания: все знания из YAML одного набора; токены Telegram из `.env`; multi-tenant нет.
- Нет версий знаний (D-4.11/D-4.12), нет приветствий по каналам (D-6.2), нет расписаний бота (D-11.1), нет распределения менеджеров (D-11.2), нет исходящих вебхуков (D-10.2), нет почтовой отправки прайса (D-9.8), нет виджета сайта (D-9.2) — канал только Telegram и API.
- Guard без режима «предупредить» (R-D-08); стоимость только в USD (R-D-25).
- Тестов на `Responder`/`Extractor` с фиксированными ответами LLM — только в `TurnTest` через фейк; «золотых» сценариев с ожидаемым результатом по шагам в репозитории нет, кроме `expect[]` в персонах и `sim/final.json`.

## 2. eyon-pilot 6.4 (`src/eyon-pilot-6-4/eyon-pilot/`)

### 2.1. Что берётся как источник логики

| Область | Файлы | Оценка | Решение |
|---|---|---|---|
| Мультитенантность | `app/Support/TenantContext.php`, `app/Http/Middleware/ResolveBrand.php`, `memberships` (роли owner/admin/member/viewer + `brand_ids` JSON), `invitations`, `audit_logs` | работает; роли и `brand_ids` совпадают с ТЗ А.12 и S-3.3 | перенести (A); добавить флаг «менеджер продаж» в `memberships.settings` для различения Редактор/Менеджер (B, R-S-16) |
| Домены и верификация | `WidgetController`/`PublicSiteController`: `/.well-known/eyon-domain-verification.txt` или DNS TXT | работает | перенести (A) |
| Виджет | `widget_settings`, `widget_events`, `widget_triggers`, `widget_invitations`; загрузчик `/widget.js?key=<public_key>&v=<release>` | работает (к RAG-продавцу M3) | перенести загрузчик и настройки; подключить к движку прототипа |
| Каналы | `Sales/Channels/{TelegramAdapter,TelegramInbound,TelegramDeepLinks,ChannelDelivery,ChannelConnections,WhatsAppAdapter(mock)}`, `channel_accounts` per бренд | Telegram работает, WhatsApp — заглушка | перенести Telegram; WhatsApp — D |
| Видение | `Services/Visibility/*`: `QuizFlow` (8 шагов, ограничения отличаются от ТЗ — R-V-01…04), `VisibilityPlan` (ровно 20 вопросов), `MeasurementRunner`, `ReportPreparation` (`SOURCE_TYPES`), `KnowledgeAtom*`, `SiteInspector` (16 + 4 проверок), `Metrics::readiness`; таблицы `visibility_quizzes`, `question_candidates`, `measurement_answers`, `answer_facts/mentions/sources`, `entities`, `audit_runs/steps/snapshots/reports`, `report_publications`, `snapshot_confirmations`, `monitoring_schedules`, `competitors`, `brand_achievements` | работает (пилот) | перенести логику замера/отчёта, переработать анкету и размер набора |
| ИИ-шлюз | `Services/Ai/{AiGateway,AiBudget,AiCostAttribution,AiDataPolicy,AiContactMasker,AiCooldown,OpenRouterClient,DeepSeekClient,ObjectCostReport}`; `ai_calls`, `ai_budgets`, `object_costs`; `config/eyon.php` провайдеры (`active`/`planned`) | работает | перенести как общий шлюз; LLM-клиент прототипа заменить адаптером к этому шлюзу (сохранив параметры модели/температуры) |
| Каталог | `Knowledge/{CatalogReader (CSV/XLSX собственный парсер), CatalogImport, CatalogLookup}`; `catalog_imports`, `catalog_items` | работает | перенести; `check_stock` подключить к `catalog_items` (B) |
| Уведомления | `Services/Notifications/*`, `cabinet_notifications`, `notification_settings`, `notification_deliveries` | работает (email/Telegram) | перенести |
| Собственный продавец M3 | `Services/Sales/Seller/*` (24 класса, ~6 100 строк: `SellerTurn`, `SellerScenario`, `SellerGuards`, `SellerDialoguePolicy`, `SellerShadow`, …), `SellerRuntime`, `KnowledgeRetrieval` (RAG) | иная, упрощённая модель диалога | **не переносится** — заменяется движком прототипа (ТЗ §0.3); из него берутся только `ConversationActions`/`WidgetConversations` (операции с диалогом в UI) и `SellerReviewQueue` как идея очереди ревью |
| Знания M3 | `knowledge_documents`, `knowledge_releases`, `knowledge_atom_heads/revisions`, `SiteKnowledgeImporter`, `KnowledgePromptVersions` | модель атомов/чанков для RAG | паттерн «релиз знаний» переиспользуется концептуально (R-D-11), таблицы — новые под структуры прототипа; `SiteKnowledgeImporter` — для D-4.10 «ссылка» |
| Eval | `eval_sets`, `eval_cases`, `eval_runs` | для M3 | заменить на персоны/прогоны прототипа |

### 2.2. Отсутствует в обоих проектах

Тарифы/лимиты (`plans`), исходящие вебхуки (S-5.2/D-10.2), API-ключи по бренду с правами (S-5.2 — в прототипе один `AGENT_API_TOKEN`), PDF-экспорт, объединение контактов (D-3.4), импорт контактов CSV (D-3.5), MFA для клиентов (в eyon — только `/staff/mfa`), экстренный стоп всех ботов и режим per канал (D-9.1), таймер «никто не взял» (D-5.3), публичный отчёт Видения с TTL (есть `report_publications` без TTL — проверить при переносе), удаление компании с отсрочкой (S-7.4).

## 3. Принципы переноса ИИ-продавца

1. **Код правил переносится почти дословно**: `Policy`, `LeadState`, `Goal`, `Guard` (детерминированная часть), `ToolRunner` (структура), `Summarizer`, `Supervisor`, промпты — в новое пространство имён `App\Seller\Engine\*`. Меняются только источники данных: вместо `Knowledge::load()` из YAML — `KnowledgeSnapshot` из БД (загруженный релиз бренда); вместо `.env` — настройки бренда/канала; вместо `OpenRouterClient` — адаптер к `AiGateway`.
2. **Эквивалентность доказывается тестами** (08): (а) существующие 31 тест прототипа переносятся и должны проходить на новом движке с загруженным «эталонным релизом знаний», созданным из тех же YAML; (б) до переноса в прототипе прогоняются сценарии `tools/personas.yaml` с фиксированными (записанными) ответами LLM и сохраняются как фикстуры «вход → Goal/Guard/tools/stage»; после переноса сравниваются.
3. **Любое изменение поведения** (R-D-08 warn, R-D-18 интервалы напоминаний, R-D-36 цены из каталога, R-D-19 действие при off-topic) включается настройкой, значение по умолчанию = текущее поведение прототипа. Исключение — там, где ТЗ прямо требует иное и это зафиксировано в 09.
4. **YAML → БД** без потери: каждая структура хранится в JSON-колонках с той же схемой, что и YAML (валидируется JSON-схемой), а `KnowledgeSnapshot::toArray()` даёт массив, байт-в-байт эквивалентный `Knowledge::all()` прототипа для эталонного релиза (тест `KnowledgeEquivalenceTest`). Это позволяет не переписывать `Knowledge::*` методы: они читают из массива.
