# 04c. Настройки: полный путь от поля до теста

Формат строки: экран/поле → валидация → API/Action → хранение → применение в логике → права → тест. Готовность по ТЗ в скобках. Дефолт = значение из кода прототипа/eyon, если указано «(=код)».

## Оболочка

| Настройка | Поле UI | Валидация | Action / endpoint | Хранение | Применение | Права | Тест |
|---|---|---|---|---|---|---|---|
| Название организации (A) | S-2.1 «Название» | required, max 120 | `PATCH /settings/organization` → `UpdateOrganization` | `organizations.name` | шапка, письма | owner, admin | feature: viewer → 403, admin → 200, audit_log создан |
| Передача владения (B) | S-2.1 модал, ввод названия | confirm == name, новый владелец — активный admin | `POST /settings/organization/transfer` → `TransferOwnership` (логика `eyon:org-transfer-owner`) | `memberships.role` обеих сторон, `organizations.owner_user_id` | политики | owner | feature: роли меняются атомарно, уведомления обоим |
| Удаление организации (B) | S-2.1 | confirm, нет активных подписок | `DELETE /settings/organization` → soft delete + job очистки через 30 дней | `organizations.deleted_at` | блок входа | owner | feature + job |
| Бренд: название, сайт, логотип (A) | S-2.3 Общее | url, image ≤ 2 МБ | `PATCH /brands/{brand}` | `brands.*`, диск | меню брендов, виджет | owner, admin | feature + загрузка файла |
| Тема (A) | S-2.3 Тема, S-0.6b | in: confectionery/pyro/neutral | то же | `brands.theme` | `data-theme` на `<html>`, токены CSS (06) | owner, admin | feature + e2e смена темы |
| Подпись под логотипом, промо-карта (B) | S-2.3 Тема | max 60; bool | то же | `brands.settings.theme` | сайдбар | owner, admin | feature |
| Домены (A) | S-2.3 Домены | hostname, уникален в бренде | `POST /brands/{b}/domains`, `POST …/verify` → `VerifyDomain` (файл или DNS TXT — из eyon) | `brand_domains` | CORS виджета, доверенные origin | owner, admin | feature с фейком HTTP/DNS |
| Модули бренда (A) | S-2.3 Модули | bool per модуль; выключение Продавца требует отсутствия активных каналов или подтверждения | `PATCH /brands/{b}/modules` | `brands.modules` | подменю, маршруты модуля → 404 при выключении | owner, admin | feature: выключенный модуль → 404 |
| Участники: роль и бренды (A/B) | S-3.2 селект из 5 подписей (R-S-16), чекбоксы брендов | role ∈ owner/admin/member/viewer; флаг `sales_manager` только для member; нельзя понизить последнего owner; brand_ids ⊆ брендов организации | `PATCH /team/{membership}` | `memberships.role`, `memberships.settings.sales_manager`, `memberships.brand_ids` | Gates | owner, admin | feature матрица ролей |
| Отключить участника (A) | S-3.1/S-3.2 | не себя, не owner | `POST /team/{m}/disable` | `memberships.status` | middleware → logout | owner, admin | feature |
| Приглашение (A) | S-3.3 | email, role, brands ⊆ брендов организации | `POST /team/invitations` → письмо | `invitations` | S-0.4 принятие | owner, admin | feature + mail fake |
| Тарифы (D) | S-4.x | — | только чтение `plans` | `plans`, `organization_plan` | лимиты (`PlanLimits::get(key)`) | все | feature: без лимита → «не задано» |
| Уведомления личные (A) | S-7.1 матрица | event_key ∈ словаря, channel ∈ email/telegram/cabinet | `PUT /settings/notifications` | `notification_settings` | слушатели событий | все (свои) | feature: событие → доставка по матрице |
| Профиль, пароль, MFA (A/B) | S-5.x | текущий пароль; TOTP код | `PATCH /profile`, `POST /mfa/enable` | `users.*` | login flow S-0.1 | все (свои) | feature + e2e логина с MFA |
| API-ключи бренда (B) | S-5.2 «Создать ключ», права (чтение / ход диалога / лиды), «Отозвать» | name ≤ 60; scopes ⊆ словаря | `POST /integrations/api/keys` → `CreateApiKey` (Sanctum, abilities), `DELETE …/{key}` | `personal_access_tokens` (tokenable = brand), показ один раз | middleware `abilities` на `/api/v1/*` | owner, admin | feature: ключ без scope `leads` → 403 на `POST /api/v1/leads`; отозванный → 401 |
| Вебхуки компании (B) | S-5.2 URL, события, секрет, «Отправить тест» | https URL; events ⊆ словаря (новый лид, передача, контакт получен, замер готов, отчёт опубликован) | `POST /integrations/api/webhooks`, `POST …/{id}/test` | `webhooks`, `webhook_deliveries` | слушатели доменных событий → job доставки с HMAC и повторами | owner, admin | feature с фейком HTTP: подпись верна, 5 попыток, журнал 10 последних |
| Telegram-чат компании и почта докладов (A/B) | S-5.3 «Подключить» (код), адреса почты | код deep-link валиден; email[] | `POST /integrations/notifications/telegram`, `PUT …/emails` | `channel_accounts(type=telegram_notify)` или `organizations.settings.notify`, `notification_settings(scope=company)` | доставка уведомлений handoff/докладов (замена `TELEGRAM_MANAGERS_CHAT`) | owner, admin | feature: событие `ConversationHandedOff` доставляется в чат компании |
| Согласия (A) | S-7.4 маркетинг (переключатель), просмотр дат согласий | bool | `PATCH /settings/data/consents` | `users.consents` JSON (с датами) | рассылки проверяют флаг | все (свои) | feature |
| Экспорт данных компании (A) | S-7.4 модуль + период → «Подготовить архив» | module ∈ vision/seller; период ≤ 365 дней | `POST /settings/data/exports` → job | `data_exports` (file_path, sha256) | файл + хэш на диске | owner | feature: job пишет файл, SHA-256 совпадает |
| Удаление аккаунта/компании (B) | S-7.4 опасная зона | confirm = название; отсрочка 14 дней | `DELETE /settings/data/account`, `DELETE /settings/data/organization` | `organizations.purge_scheduled_at`, `users.purge_scheduled_at` | scheduler `data:purge`; отмена до срока | owner (компания), все (свой аккаунт) | feature с time travel |
| Реквизиты плательщика (B) | S-4.5 название, ИНН, КПП, адрес, почта для счетов | ИНН 10/12 цифр, КПП 9 | `PUT /billing/payer` | `organizations.settings.payer` | отображение; счета — D | owner | feature |
| Доступ поддержки на 72 ч (A) | S-8.2 чекбокс | bool | `POST /help/support` (ticket + grant) | `support_access_grants(expires_at)` | режим `admin.support` проверяет срок | все | feature: доступ истекает (time travel) |
| Telegram для уведомлений (A) | S-7.2 | deep-link код | `TelegramDeepLinks` (eyon) | `users.telegram_chat_id` | доставка | все (свои) | feature |

## Видение

| Настройка | Поле UI | Валидация | Action | Хранение | Применение | Права | Тест |
|---|---|---|---|---|---|---|---|
| Анкета 8 шагов (A) | V-1.1–V-1.8 | по шагу: url, ≤10 конкурентов, ≤10 фактов, ≤15 вопросов (R-V-01…03) | `PATCH /vision/quiz/step/{n}` → `QuizFlow::saveStep` (переписан) | `visibility_quizzes.step_data` | генерация профиля V-1.9 и набора вопросов | member (редактор) и выше | unit лимитов; feature по шагам |
| Набор вопросов (A) | V-2.x | размер ≤ `plan.vision.questions`, хэш | `QuestionSets::publish` | `question_sets`, `questions` | `VisibilityPlan` без константы 20 (R-V-05) | member (редактор) и выше | unit: размер 12/20/30 допустим |
| Модели для замера (A) | V-2.3, V-11.1 | ⊆ `active` провайдеров, ≤ `plan.vision.models` | `PATCH /vision/settings/models` | `brand_vision_settings.models` | `MeasurementRunner` | admin+ | feature: planned-модель → 422 |
| Расписание (A) | V-11.2 (вход с V-3.6) | frequency ∈ weekly/biweekly/monthly, weekday, time, tz | `PUT /vision/schedule` | `monitoring_schedules` | `vision:measurements` scheduler | admin+ | feature + time travel |
| Лимит слотов (C) | V-11.3 | только чтение | — | `plan_limits` + счётчик `measurements` | блок запуска при исчерпании | все | feature: лимит достигнут → кнопка disabled, API 422 |
| Уведомления модуля (A) | V-11.4 | event_key ∈ 6 событий | как S-7.1 | `notification_settings` | события `MeasurementCompleted` и др. | все | feature |
| Публикация отчёта (A/C) | V-5.10 | TTL ∈ {7,30,∞} (C) | `POST /vision/reports/{r}/publish` | `report_publications` | публичная страница V-5.P | member (редактор) и выше | feature: истёкший токен → 410 |
| Действия (A) | V-9.1 | статус, ответственный ∈ members, срок | `PATCH /vision/actions/{a}` | `actions` | дашборд V-0.x | member (редактор) и выше | feature |

## ИИ-продавец

| Настройка | Поле UI | Валидация | Action | Хранение | Применение | Права | Тест эквивалентности/поведения |
|---|---|---|---|---|---|---|---|
| Факты (A) | D-4.3/4.4 | текст ≤ 500, группа существует | `POST/PATCH /seller/knowledge/facts` (draft release) | `kn_facts`, `kn_fact_groups` | `Knowledge::factsForIntent` из snapshot | admin, member (редактор) | `KnowledgeEquivalenceTest`; Responder-промпт содержит факт |
| Условия (A) | D-4.5 | число/текст по схеме ключа; `max_pct_unapproved` 0–100 (=30) | `PUT /seller/knowledge/conditions` | `kn_conditions` | `{cond:…}` в триггерах, `Guard::badNumbers`, `pct_above_unapproved` | admin | GuardTest: число вне условий → issue; per-условие approved=false → Guard как сейчас при глобальном флаге |
| Темы/интенты (A) | D-4.6/4.7 | key slug, уникален | `…/intents` | `kn_intents` | `Knowledge::intents`, `factsForIntent`; `default_answer`/`after_action` (B) — доп. блок промпта только если заполнены | admin | снапшот промпта без полей == эталон |
| Правила Guard (A/B) | D-4.9 | enabled bool, severity block/warn (=block), числовые параметры | `…/guard-rules` | `kn_guard_rules` | `Guard::check` — warn пишет `meta.guard.warnings`, не регенерирует | admin | GuardTest 13 + 3 новых на warn |
| Лимиты речи (A) | D-4.9/D-6.1 | max_words 20–300 (=90), target [min,max], max_questions 1–2 (=1) | `…/persona` | `kn_persona.limits` | `Guard` too_long/two_questions, промпт responder | admin | GuardTest too_long с границей |
| Персонаж (A) | D-6.1 | name ≤ 40, tone[] ≤ 8, intro ≤ 400, form_bans[] | `…/persona` | `kn_persona` | промпт responder | admin | снапшот промпта |
| Приветствия (B) | D-6.2 | per channel/section ≤ 300 | `…/greetings` | `brand_seller_settings.greetings` | `Orchestrator::open` — если задано, используется вместо генерации; иначе = код | admin | TurnTest open: пусто → генерация; задано → текст |
| Служебные фразы (A) | D-6.3 | ≤ 300 каждая | `…/phrases` | `brand_seller_settings.phrases` (fallback_reply, opt_out_ack, decline_*) | `Policy`/`Orchestrator::safeFallback` читают из настроек, дефолт = YAML/код | admin | тест: дефолт совпадает с прототипом |
| Порядок слотов и лимиты (C, чтение) | D-5.1/D-5.2 | — (v1 только чтение) | — | `kn_policy` | `Policy` | все | отображение значений из snapshot |
| Причины передачи (A) | D-5.3 | enabled, priority ∈ urgent/normal, message_idea ≤ 300, after (B) | `…/escalation` | `kn_escalation` | `ToolRunner::escalate`, `Policy` | admin | unit per reason; выключенная причина не срабатывает (кроме `manual`, `want_human`) |
| Таймер «никто не взял» (B) | D-5.3 | minutes 5–240 или null | то же | `kn_escalation.handoff_timeout_minutes` | job `seller:handoff-timeouts` → уведомление/повторное | admin | feature с time travel |
| Приёмы (A) | D-5.4 | enabled; when/not_when по схеме; is_base | `…/triggers` | `kn_triggers` | `Policy` выбор триггера, `max_uses_per_dialog` | admin | PolicyTest трiггеры; статистика из `messages.meta.goal.trigger.id` |
| Напоминания (A/B) | D-5.5 | first_hours (=20), second_hours (null = старое поведение 20×n), max_auto (=2), окно, тексты | `…/followups` | `brand_seller_settings.followup` | `Jobs\SendFollowups` | admin | тест «null → поведение FollowupsSend», тест двух интервалов, тест окна |
| Вне темы/негатив (A) | D-5.6 | streak 1–10 (=3), action spam/handoff (=spam), negative_streak (=2) | `…/off-topic` | `brand_seller_settings.off_topic/negative` | `Policy` вместо констант `policy.limits` | admin | PolicyTest с дефолтами == прежние |
| Действия бота (A) | D-5.7 | тумблеры (кроме всегда включённых), зоны доставки (regex/город, цена, дни), реквизиты ≤ 1000 | `…/tools` | `brand_seller_settings.tools` | `Engine\Tools\*`; дефолт зон = таблица из `ToolRunner::calcDelivery` | admin | unit per tool: дефолты == прототип |
| Каналы: Telegram (A) | D-9.5 | токен валиден (getMe), chat_id менеджеров | `POST /seller/channels/telegram` | `channel_accounts` (enc) | `TelegramInbound`, уведомления handoff | admin | feature с фейком Telegram API |
| Виджет (A) | D-9.2/9.3 | домен верифицирован | `widget_settings` | — | `/widget.js?key=…` | admin | feature |
| Режим канала (A) | D-9.x | bot/suggest/manager | `PATCH /seller/channels/{c}` | `channel_accounts.default_mode`, `conversations.mode` | `Orchestrator`: suggest → подсказка, manager → без бота | admin, member (редактор) | TurnTest по режимам |
| API-токен бренда (A) | D-9.9 | — | `POST /seller/channels/api/token` | Sanctum | `POST /api/v1/leads` | admin | feature |
| Каталог (A/B) | D-10.1 | файл ≤ 20 МБ CSV/XLSX; YML URL (B) | `POST /seller/catalog/import` → `CatalogImport` | `catalog_items` | `check_stock` (B), цены в промпт при `catalog_prices_enabled` (B, =false) | admin | парсер на фикстурах; Guard-тест с ценой из каталога |
| Вебхуки (B) | D-10.2 | https url, events ⊆ словаря | `…/webhooks` | `webhooks` | слушатели → `webhook_deliveries` (HMAC) | admin | feature с фейком HTTP, повторы |
| Расписание бота (B) | D-11.1 | интервалы по дням, tz, текст вне часов | `…/schedule` | `brand_seller_settings.schedule` | `Orchestrator` до Extractor: вне часов → ответ-заглушка + followup | admin | TurnTest с time travel |
| Распределение менеджеров (B) | D-11.2 | round_robin/manual, список | `…/assignment` | `brand_seller_settings.assignment`, `conversations.assigned_user_id` | при handoff | admin | unit распределения |
| Стоимость/лимит (C) | D-11.3 | чтение | — | `ai_budgets` | `AiGateway` блокирует при исчерпании | все | feature |
| Режим канала и экстренный стоп (B) | D-9.1 сегмент Автономно / Подсказки / Выключен на карточке канала; «Остановить всех ботов» | mode ∈ bot/suggest/manager; подтверждение | `PATCH /seller/channels/{c}/mode`, `POST /seller/channels/stop-all` | `channel_accounts.default_mode`, `brands.seller_paused_at` | `Orchestrator`: paused → ни один канал не отвечает, входящие сохраняются; suggest → подсказка | admin, member (режим), owner/admin (стоп) | TurnTest: paused — сообщение сохранено, ответа нет, задача менеджеру создана |
| Образцы речи (A) | D-4.8 карточка: ситуация, ответ, тег, confidence, включён, bad example | тег ∈ словаря тегов релиза; confidence 0–1 | `POST/PATCH /seller/knowledge/phrases` (draft) | `kn_fewshot` | `Knowledge::fewshot(tags, clientType, 6, 0.7)`, `badExamples` | admin, member (редактор) | эквивалентность на эталоне; новый образец с confidence < 0.7 не попадает в промпт |
| Закрытые темы (A) | D-4.9 вкладка «Закрытые темы»: тема, фраза, что дальше | текст ≤ 300; next ∈ return/handoff | `…/closed-topics` | `kn_closed_topics` | `Knowledge::closedTopics`, `Policy` (тег `decline_*`), Guard `promise_to_clarify_on_closed` | admin | PolicyTest закрытой темы |
| Маппинг входящих форм (A/B) | D-9.9 таблица «поле формы → слот карточки», действие с новым лидом | slot ∈ `LeadState::SLOTS`; action ∈ open/card_only | `PUT /seller/channels/api/mapping` | `channel_accounts(type=api).settings.mapping` | `POST /api/v1/leads` применяет маппинг; `open` → `Orchestrator::open` | admin | feature: поле формы попадает в слот; `card_only` не создаёт сообщение |
| Прайс-файл для отправки (A/B) | D-10.1 выбор файла | файл ∈ импортов/загрузок бренда | `PUT /seller/integrations/catalog/price-file` | `brand_seller_settings.tools.send_price.file_id` | `send_price` (E5-07) | admin | feature: письмо содержит файл |
| Порог «мало» для остатков (B) | D-10.4 | int ≥ 0 | `PUT /seller/integrations/stock` | `brand_seller_settings.tools.check_stock.low_threshold` | `check_stock` формулирует «мало» при stock ≤ порога | admin | unit |
| Объединение контактов (B) | D-3.4 «Объединить / Разные» | оба контакта одного бренда | `POST /seller/customers/merge` → `MergeContacts` | `contacts.merged_into_id`, переназначение `conversations.contact_id`, `seller_tasks` | карточка показывает объединённые диалоги | admin, member (редактор) | unit: диалоги/задачи переназначены, аудит, идемпотентно |
| Уведомления модуля (A) | D-11.4 | 7 событий | как S-7.1 | `notification_settings` | события продавца | все | feature |
