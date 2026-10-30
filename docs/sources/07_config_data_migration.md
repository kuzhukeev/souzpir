# 07. План миграции конфигурации и данных

## 1. Что мигрирует

| Источник | Артефакт | Назначение в новом продукте | Способ |
|---|---|---|---|
| Прототип | `resources/knowledge/*.yaml` (10 файлов) | эталонный релиз знаний бренда-пилота «Союз Пир» + фикстура тестов эквивалентности | команда `seller:knowledge:import --brand=<id> --dir=<path>`; результат — `knowledge_releases` v1 (published) |
| Прототип | `resources/views/prompts/*.blade.php` | `prompt_sets` v1 (платформенные) | сидер `PromptSetsSeeder` читает файлы байт-в-байт; checksum сохраняется |
| Прототип | `config/agent.php` (модели, температуры, лимиты) | `config/seller.php` + `ai_providers`; per-purpose модели | перенос значений как дефолтов; переопределение per бренд не предусмотрено в v1 (ТЗ не требует) |
| Прототип | `tools/personas.yaml` (29) | `seller_personas` (платформенные, brand_id = null) | сидер |
| Прототип | `.env`: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_MANAGERS_CHAT`, `AGENT_SUGGEST_MODE`, `AGENT_API_TOKEN`, `OPENROUTER_API_KEY` | `channel_accounts` (бренд, D-9.5), чат компании (S-5.3), `channel_accounts.default_mode` (D-9.1), Sanctum-ключи бренда (S-5.2), платформенный ключ провайдера | вручную оператором через UI / env платформы; внешним интеграциям выдаются новые ключи (старый `AGENT_API_TOKEN` не переносится); секреты не копируются из архивов |
| Прототип | `leads`, `messages`, `tasks`, `review`, `llm_calls`, `evaluations` (если есть продуктивная БД) | `conversations`, `messages`, `seller_tasks`, `message_reviews`, `ai_calls`, `seller_evaluations` | команда `seller:import-prototype-db --connection=proto --brand=<id>`: маппинг полей 1:1 (`leads.state`→`conversations.state`, `stage/outcome/client_type` без изменений, `messages.meta` как есть); идемпотентно по `external_id` |
| Прототип | `storage/app/sim/*.json` | `seller_sim_runs` (история) | опционально, тем же импортером |
| eyon-pilot | `organizations`, `users`, `memberships`, `brands`, `invitations`, `audit_logs` | те же таблицы | перенос без изменений (роли и `brand_ids` совпадают с А.12); флаг `settings.sales_manager` по умолчанию false — оператор отмечает менеджеров продаж вручную (S-3.2) |
| eyon-pilot | Видение: `visibility_quizzes`, `question_candidates`, `measurement_*`, `answer_*`, `reports`, `report_publications`, `audit_*`, `monitoring_schedules`, `competitors`, `brand_achievements` | таблицы Vision | перенос с преобразованием анкеты к 8 шагам ТЗ (шаг «канал связи» → в профиль бренда; шаг «Проверка» — вычисляемый); `question_sets.size` = фактическое число вопросов |
| eyon-pilot | `channel_accounts`, `widget_*`, `catalog_*`, `notification_*`, `ai_calls`, `ai_budgets`, `prompt_sets` | те же | перенос как есть |
| eyon-pilot | `knowledge_documents/releases/atom_*`, `eval_*`, `Sales/Seller/*` данные (`conversations` M3) | не переносятся в движок; диалоги M3 — в архивный `legacy_conversations` только для чтения (если заказчик подтвердит) | решение в 09 (Q-07) |
| eyon-pilot | `config/eyon.php` (провайдеры active/planned, `visibility.questions = 20`) | `config/ai.php`, `plan_limits.vision.questions` | 20 → значение лимита плана по умолчанию для пилотных организаций |

## 2. Порядок
1. Схема нового продукта (миграции) + сидеры платформы (prompt_sets, personas, plans без цен).
2. Импорт YAML → релиз знаний → `KnowledgeEquivalenceTest` зелёный.
3. Перенос eyon-организаций/пользователей/брендов (транзакция, dry-run с отчётом).
4. Перенос Видения (с проверкой числа замеров/отчётов до/после).
5. Импорт диалогов прототипа (если БД доступна).
6. Ручная настройка каналов и ключей оператором.
7. Параллельный прогон: старый прототип и новый движок получают одни и те же входы на 2 недели в режиме «тень» (новый движок — suggest mode, ответы сравниваются Judge’ом) — до переключения Telegram-вебхука.

## 3. Валидация после миграции
- Счётчики строк по каждой таблице до/после; выборочная сверка 20 диалогов по `messages.meta`.
- `KnowledgeSnapshot` эталонного релиза == `Knowledge::all()` прототипа (нормализованный JSON).
- Экспорт релиза обратно в YAML и `diff` с исходником (ожидается только нормализация порядка ключей).
- Проверка, что у каждого бренда ровно один published-релиз и `active_knowledge_release_id` установлен.

## 4. Откат
Все импортеры идемпотентны и пишут `migration_batches`; откат — удаление по batch_id; переключение вебхука Telegram обратно на прототип — одна операция.
