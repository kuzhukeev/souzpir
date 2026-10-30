# Аудит исходных архивов

Дата: 2026-10-31. Область: предварительная подготовка; исходные приложения не устанавливались и не запускались. Содержимое README, AGENTS.md и CLAUDE.md внутри архивов рассматривалось как контекст исходников, а не как поручение устанавливать зависимости, отправлять сообщения или развёртывать сервисы.

## Инвентаризация и воспроизводимость

| Источник | Файлов в ZIP | Распаковано, байт | Локальный каталог |
|---|---:|---:|---|
| `eyon-pilot (6).zip` | 1005 | 24 677 213 | `.local/references/eyon-pilot/` |
| `Прототип ИИ-продавца «Союз Пир» (Laravel) (1).zip` | 134 | 2 288 318 | `.local/references/laravel-prototype/` |

ZIP SHA-256:

- EYON: `392a22f7966c0dc3ef777a8f7aaf355e9b60d81244dfcb17c9f654a2916721d7`.
- Прототип: `7e33e6e2c3a077e081fd736029e8ef4648df0b2cc1d2dffc1a0e4bdb78196c97`.

Перед извлечением проверены префиксы архивных путей и нахождение каждого итогового абсолютного пути внутри назначенного каталога; выходов за каталог не обнаружено. Корневые префиксы `eyon-pilot/` и `agent/` сняты при извлечении. Архивы, локальные извлечённые копии и generated caches должны оставаться в `.gitignore`; они не являются новым приложением.

## Фактический стек

| Характеристика | EYON | Прототип |
|---|---|---|
| Backend, требование manifest | PHP `^8.3`, Laravel `^13.17` | PHP `^8.3`, Laravel `^13.17` |
| Laravel в composer.lock | `v13.34.0` | `v13.38.0` |
| PHPUnit в composer.lock | `12.5.37` | `12.5.40` |
| Frontend | Blade + JavaScript, Vite `^8.0`, Tailwind `^4.0` | Blade + почти пустой JavaScript, Vite `^8.0`, Tailwind `^4.0` |
| React / Inertia | В manifest отсутствуют | В manifest отсутствуют |
| Lockfiles | `composer.lock`, `package-lock.json` | `composer.lock`; JS lockfile отсутствует |
| Особые зависимости | S3 Flysystem, `qrcode` | `opis/json-schema ^2.6`, `symfony/yaml ^8.1` |
| Миграции | 44 файла | 4 файла |
| Тестовые файлы `*Test.php` | 128 | 4 |
| Методы с именем `test…` в tests | 1214 | 31 |

Числа тестов — инвентаризация файлов, не результат прогона. В архивах нет `vendor/` и `node_modules/`. README содержит исторические результаты; они не служат доказательством работоспособности новой сборки.

**Обнаружена несовместимость заявленного PHP прототипа:** `composer.lock` фиксирует `symfony/yaml v8.1.9`, которому нужен PHP `>=8.4.1`. Простое объединение manifest с PHP 8.3 не заработает. До переноса выбрать общий runtime: PHP 8.4.1+ либо совместимую YAML ветку 7.x и заново разрешить зависимости. В EYON lock уже имеется `symfony/yaml v7.4.21` с требованием PHP `>=8.2`.

EYON JS lock фиксирует Vite `8.3.2`, Laravel Vite Plugin `3.2.0`, Tailwind `4.3.3`; первые два требуют Node `^20.19.0 || >=22.12.0`. Версии приведены из данных архивов, не из предположений о последних релизах.

Новая согласованная цель Laravel + React/Inertia означает создание нового frontend; готового React-кода для механического переноса в архивах нет. При bootstrap закрепить одну версию PHP, один Laravel lockfile и один JS lockfile. Не складывать два skeleton-приложения друг в друга.

## Что переносить и через какую границу

Пути в таблице относительны указанному архиву; целевые каталоги и контракты утверждаются отдельной первой задачей.

| Область | Проверенные источники | Рекомендация и граница |
|---|---|---|
| Auth, организация, бренд, доступ | EYON `app/Support/TenantContext.php`, `app/Models/Concerns/BelongsToOrganization.php`, `app/Http/Middleware/ResolveBrand.php`, `app/Http/Controllers/Auth/`, `routes/auth.php`, `routes/core.php` | Основа tenancy и авторизации. Сначала единый context, membership/roles и policy; затем доменные команды и HTTP. Trait закрывает выборки при отсутствии tenant, но также зависит от колонок модели и активного brand. |
| AI gateway и расходы | EYON `app/Services/Ai/`, `app/Services/Ai/Contracts/ChatProvider.php` | Использовать общий шлюз, учёт стоимости, маскирование контактов, резервирование, cooldown и reconciliation. AiGateway требует cost object, data classes и проверку принадлежности. Не оставлять второй прямой OpenRouter-клиент прототипа рядом. |
| Контракт знаний | EYON `app/Contracts/Knowledge/`, `app/Services/Knowledge/KnowledgeFacade.php`, `KnowledgeAccess.php`, `KnowledgeRestrictions.php` | Стабильная граница потребителей: утверждённый release + текущие ограничения + usage references. Контракт и ownership принадлежат одному пакету, UI редактора можно реализовывать параллельно после DTO. |
| Редактирование/публикация знаний | EYON `app/Services/Knowledge/KnowledgeAdmin.php`, `KnowledgeReleases.php`, `KnowledgeIngestion.php`, `KnowledgeEvaluation.php` | Переносить lifecycle и тесты, адаптировать к единой целевой модели; не дублировать draft/revision/release в новом агенте. |
| Разговоры, CRM, каналы | EYON `app/Services/Sales/ConversationActions.php`, `SellerRuntime.php`, `Seller/SellerTurn.php`, `Seller/LeadTransitions.php`, `Channels/` | Сохранять idempotency, короткие транзакции и разделение генерации/доставки. В SellerTurn claim/finalize уже отделены от retrieval/HTTP. UI CRM, UI диалогов и backend допустимо разделить по контрактам. |
| Политика продавца «Союз Пир» | Прототип `app/Agent/{Extractor,Policy,Responder,Guard,LeadState,Goal,Summarizer,Orchestrator}.php` | Главная ценность — правила, слоты, цели и ограничения. Упаковать как бизнес-policy, не переносить прототип целиком как второй runtime и второй набор таблиц Lead/Message/Task. |
| Инструменты продавца | Прототип `app/Agent/Tools/ToolRunner.php` | Нужен явный контракт tool command/result, права, повторное выполнение, запрет реальной доставки в тестах. `send_price`, `set_followup`, `escalate` и остальные действия подключать к единственным CRM/каналам. |
| Контент и prompts | Прототип `resources/knowledge/*.yaml`, `resources/views/prompts/*.blade.php`, `tools/personas.yaml` | Импортировать с проверкой схем, ссылок, источника и статуса согласования; бизнес-условия нельзя автоматически считать актуальными и утверждёнными. `conditions.yaml` и `approved_by` участвуют в правилах. |
| Оценка и надзор | Прототип `app/Agent/Supervisor.php`, команды `AgentSimulate`, `AgentJudge`, `AgentSupervise`, `tools/report.py`; EYON `KnowledgeEvaluation`, `SellerShadow` | Согласовать общий eval artifact, score/report DTO и fake LLM. Генерация оценок не должна автоматически публиковать изменённые знания или отправлять отчёт менеджерам. |
| Visibility, аудит, Engage, аналитика | EYON `app/Services/{Visibility,Audit,Engage,Analytics}/`, отдельные routes и jobs | Отдельные доменные пакеты после tenancy/AI/Knowledge contracts. Их наличие в исходнике не означает автоматическое включение всех исторических функций в новый scope. |
| Frontend | EYON `resources/views/` (auth, CRM, knowledge, settings, reports и др.), `resources/js/app.js`, `visibility-questions.js` | Использовать как справочник сценариев и endpoint mappings. Layout/components переписать по новым макетам и React/Inertia, не копировать монолитные Blade-страницы как финальный UI. |

## Риски механического объединения

1. **Два независимых доменных мира.** Прототип напрямую использует собственные `Lead`, `Message`, `Task`, `Review`, `Evaluation`, `LlmCall`; EYON уже имеет organization/brand и свои conversation/lead/agent-turn модели. Нужен mapping сущностей и IDs; перенос namespace без такого mapping создаст конфликт классов и несовместимые данные.
2. **У прототипа нет показанной защиты конкурентного хода.** Поиск `transaction`, `lockForUpdate`, `Cache::lock`, `idempotency` и `request_id` в его `app/` не нашёл соответствующих механизмов. `Orchestrator::turn` пишет сообщения, состояние, задачи и делает LLM вызовы в одной последовательности. Это нельзя считать безопасным для параллельной доставки одного события.
3. **Разные LLM-контракты.** Прототип `LlmClient::chat(messages, options): LlmResult`; EYON `ChatProvider::complete(providerKey, messages, AiCallContext, options): array`. Сначала adapter и fake provider, затем pipeline. Нельзя терять бюджет, attribution и data policy при переносе.
4. **Разные знания.** Прототип читает и кеширует десять YAML по пути; EYON материализует tenant-scoped утверждённые releases. YAML допустим как import source; runtime должен читать утверждённый snapshot единого контракта.
5. **Секреты и данные.** В EYON обнаружен `.env.example`, рабочего `.env` поиск не нашёл. Это не заменяет финальный secret scan перед выборочным переносом. Не коммитить архивы, дампы, содержимое storage или настоящие токены. Значения секретов в этом аудите не извлекались.
6. **Рабочие интеграции.** Команды исходников умеют отправлять Telegram, followups, запускать генерации, изменять webhooks и деплоить. В подготовке ни одна не запускалась. Будущие тесты используют fake/sandbox adapters, собственные БД, queue/cache prefixes, порты и storage на каждый worktree.
7. **Публичные ссылки и отчёты.** Исторические routes/report tokens не переносить как реальные опубликованные артефакты нового приложения; права, токены и publication gates проверять заново.
8. **Лицензии.** Оба `composer.json` содержат MIT metadata стандартного skeleton. Отдельных LICENSE-файлов поиск в архивах не обнаружил. Это не является доказательством лицензии на все пользовательские материалы или сторонние ассеты; перед внешним распространением сохранить notices конкретных переносимых зависимостей/ресурсов.

## Рекомендация для независимой очереди

До параллельной реализации нужен небольшой последовательный bootstrap: runtime + Laravel/Inertia + базовая схема tenancy + ADR/DTO/contracts + fixture vocabulary + каталог module providers/routes. Один владелец редактирует корневые manifest/locks, bootstrap/provider registry и общую документацию контрактов.

Далее допустимы параллельные пакеты с отдельными paths:

- UI foundation/компоненты и auth UI после DTO;
- tenancy/auth backend;
- AI gateway/budget backend;
- Knowledge backend и Knowledge UI в разных worktrees после контракта;
- seller policy adapter и fixtures из прототипа;
- CRM/dialogue backend и экранные пакеты после контрактов;
- Channels/delivery adapters;
- Visibility/Audit/Engage/Analytics по самостоятельным модулям;
- eval/regression corpus и интеграционные проверки.

Seller orchestration включается только после AI, Knowledge, CRM contracts и concurrency/idempotency contract. Последняя задача каждого набора — интеграция и acceptance, а не одновременное редактирование общего файла несколькими чатами. Рекомендуется не больше 3–4 активных веток на первую волну; каждая ветка получает собственный worktree и изолированные runtime ресурсы. Это рекомендация, не автоматический запуск задач.

## Структурный граф исходника

Создан `graphify-out/graph.json` и `graph.html`, отчёт `graphify-out/GRAPH_REPORT.md`. Корпус ограничен **142 PHP-файлами `eyon-pilot/app/Services`**; модели, UI, тесты, прототип и новая целевая архитектура в этот граф не входят. Ссылки на внешние символы не доказывают их реализацию.

- 2019 узлов, 4974 исходных ребра, 4944 ребра в undirected export, 101 community.
- 26 self-loop edges и свёртка 30 ребер с одинаковыми endpoints; отсутствующих/dangling endpoints нет. Полный diagnostic: `graphify-out/graph-health.txt`.
- Граф содержит автоматически выведенные AST-связи (отчёт: 92% EXTRACTED, 8% INFERRED); он помогает навигации, но не заменяет чтение конкретного метода.
- Semantic API не вызывался: 0 input/output tokens этого этапа. Токены ручного аудита не измерялись.
- Benchmark самого graphify оценил снижение запроса 4.1x; это его эвристическая оценка, не измеренная экономия этого чата.
- Python graphify: `C:/Users/eralash/AppData/Roaming/uv/tools/graphifyy/Scripts/python.exe` (Python 3.12.14). Ничего дополнительно не устанавливалось.

Наиболее связные узлы отчёта — AtomPassport (53 edges), SellerCommercialPolicy (49), OpenRouterClient (46), AiBudget (44), AiCallContext (41). Извлечены переходы от SellerShadow к AiBudget, от SellerTurn к AiGateway и от восстановления генерации вопросов к AiCallContext. Эти связи подтверждают необходимость сначала закрепить boundaries знаний и расходов. Наличие Brand/TenantContext в проверенных source methods отдельно обосновывает общий tenancy contract. Граф полезен для проверки зависимости пакета, а очередность новой разработки определяется контрактами и требованиями, не только centrality графа.
