# Эталон прототипа продавца: offline evidence и операторская запись

SP-T-01, 31.10.2026. Донор — архив Laravel-прототипа «Союз Пир», не M3 из EYON. Исходные PHP, YAML, prompts и тестовые ожидания не изменены. Runtime-копия и зависимости находятся в игнорируемом `.local/prototype` этого worktree.

## Что зафиксировано в SP-T-01 (v1)

`tests/fixtures/prototype/manifest.json` содержит ровно **41 case ID**: 29 персон из `tools/personas.yaml` донора и 12 ручных сценариев из «2.1 плана тестирования».

| Вид данных | Количество | Происхождение |
|---|---:|---|
| Реальные диалоги LLM (`recorded`) | 0 | Реальные записи не предоставлены, платный прогон не выполнялся |
| Персоны без записи (`missing`) | 29 | ID, названия, max_turns и ожидания перенесены из исходного YAML; диалоги не выдуманы |
| Ручные traces (`synthetic`) | 12 | 10 запусков исходной Policy с явно заданным extraction; 2 сценария исходного scheduler на SQLite `:memory:` |
| Rendered prompt snapshots | 20 | 4 реальных Blade-шаблона × 5 фиксированных состояний |
| Synthetic Orchestrator replay | 1 сценарий / 3 LLM-вызова | Исходный Orchestrator, ответы исходного FakeLlmClient, начало диалога и заполнение города/цели |

Synthetic traces проверяют детерминированное поведение и инфраструктуру replay. Они не доказывают качество извлечения реальной моделью, естественность ответов или 100% эквивалентность перенесённого движка. Операторская запись SP-T-03 имеет отдельный формат v2, описанный ниже. Этот исходный пакет v1 не перемаркируется и не заменяется.

## Ручные сценарии

| ID | Проверяемая граница |
|---|---|
| `manual.opt_out` | Отказ от дальнейших сообщений |
| `manual.prompt_injection` | Отказ выполнять подмену инструкций |
| `manual.off_topic_three` | Три последовательных оффтопа; третий приводит к silent/spam |
| `manual.negative_twice` | Второй негатив приводит к handoff |
| `manual.large_order` | Порог 500000 из исходных условий: уведомление менеджеру, продолжение диалога |
| `manual.deferred_twice` | Повторная отсрочка приводит к handoff |
| `manual.wants_human` | Явный `want_human` приводит к handoff; одиночный boolean-намёк не подменяется явной просьбой |
| `manual.closed_topic` | Вопрос об имени руководителя |
| `manual.no_repeat_filled_slot` | Уже заполненный город не спрашивается снова |
| `manual.force_close_14` | После 14 applyExtraction происходит переход в close_contact |
| `manual.followup_20h` | До 20 часов напоминания нет, на границе оно создаётся |
| `manual.followup_40h` | Второе напоминание на 40 часах от исходной реплики; затем фактическая граница stale на 80 часах |

Последняя граница — фактический результат алгоритма донора: после второго сообщения следующая проверка ожидает ещё `20 × 2` часов. Она сохранена как наблюдение, а не исправлена по формулировке комментария. Начальный момент соответствует 09:00 Москвы / 06:00 UTC; для записи в SQLite используется UTC, как в доноре.

Policy-only traces не имитируют последующую обработку Orchestrator, вызовы инструментов или доставку. В manifest и каждом файле указан слой проверки. Scheduler выполняет только channel=`test`, Telegram token пуст; транспортные функции отключены.

## Prompt snapshots и нормализация

Состояния: `opening`, `newbie_qualified`, `experienced_price`, `opt_out`, `deferred_escalation`. Для каждого отрендерены `extractor`, `responder`, `guard`, `summary` из исходных Blade views; responder сформирован реальным `Responder::systemPrompt`. SHA-256 содержится в manifest. Локальный `.gitattributes` фиксирует LF для fixtures, чтобы Windows checkout не менял байты и snapshot hashes.

Перед сохранением нормализуются переводы строк и редактируются секреты/почтовые адреса. Поэтому snapshots — проверяемое безопасное представление реально отрендеренного текста, а не обещание исходных байтов с контактами. После переноса применяется та же нормализация. Пять входных состояний сохранены отдельно; часы заморожены. Порядок JSON-ключей канонизируется, порядок списков сохраняется; пустой объект отличается от пустого списка. Из request options удаляются только runtime `timestamp`, `request_id`, `trace_id`, `lead_id`, `message_id`; даты внутри сообщений и слотов сохраняют смысл. Из результатов диалогов исключены только wall-clock latency и DB message ID.

`source-fingerprints.json` фиксирует SHA исходного app, config, knowledge, prompt views, tests, tools и composer.lock. Проверка against оригинального read-only донора подтверждает, что результаты получены на неизменённой логике.

## Recording/replay harness

`tools/baseline/FixtureClient.php` реализует интерфейс донора `LlmClient`:

- `synthetic` принимает только собственный FakeLlmClient; у него нет live delegate. Запись помечается synthetic и редактируется до записи на диск.
- `replay` читает последовательные fixtures, сверяет SHA и нормализованный запрос; отсутствие записи, несовпадение или неиспользованный остаток приводит к ошибке. Сетевого fallback нет.
- Повторная запись в каталог с evidence запрещена. Команда `build.php` также отказывается перезаписывать output. Новые кандидаты генерируются в новый каталог, сравниваются и рассматриваются отдельно.
- Этот клиент v1 не запускает реальную модель даже по параметру `live`. Операторский recorder v2 использует отдельные классы и отдельный каталог; ограничения `FixtureClient` и `bootstrap.php` сохранены.

## Воспроизведение

Интерпретаторы текущего компьютера:

```powershell
$php = 'C:\OSPanel\modules\PHP-8.4\php.exe'
$python = 'C:\Users\eralash\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe'
$offline = @('-d', 'opcache.enable_cli=0', '-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,mail')
```

Подготовить отдельную `.local/prototype` из read-only донора, не создавать в ней `.env`. Установить **из donor composer.lock** зависимости через Composer `install --no-scripts --no-plugins --prefer-dist --no-interaction`, задав `COMPOSER_HOME`, `COMPOSER_CACHE_DIR` и `sys_temp_dir` внутри своей `.local`. Команды из donor AGENTS/README автоматически не исполняются. Нужны пустые writable `bootstrap/cache`, `storage/framework/{views,cache/data,sessions}` и `storage/logs`; ZIP не содержит всех пустых каталогов.

```powershell
& $php @offline tools/baseline/build.php --prototype=.local/prototype --output=.local/baseline-candidate
& $php @offline tools/baseline/selftest.php --prototype=.local/prototype --temporary=.local/baseline-selftest
& $python tools/baseline/verify.py --prototype='C:/OSPanel/home/souzpir.local/.local/references/laravel-prototype' --compare=.local/baseline-candidate
```

`bootstrap.php` проверяет disabled functions/URL wrappers, отказывается загружать `.env` и оригинальный каталог references, принудительно выбирает fake LLM, SQLite `:memory:`, array cache/session/mail, запрещает stray HTTP и подменяет Mail. Не убирайте эти проверки ради запуска. Исходные тесты запускаются из `.local/prototype` теми же PHP-флагами; дополнительно задать `LLM_DRIVER=fake`, пустые `OPENROUTER_API_KEY` и `TELEGRAM_BOT_TOKEN`, `APP_ENV=testing`, фиктивный APP_KEY. Не использовать рабочие ключи.

```powershell
Push-Location .local/prototype
& $php @offline vendor/bin/phpunit --colors=never --log-junit ../prototype-junit.xml
Pop-Location
```

Для исходного пакета v1 `verify.py --require-recorded` по-прежнему обязан завершиться ненулевым кодом: recorded 0/41. Обычная проверка v1 подтверждает корректность offline-пакета и явно выводит missing/synthetic. Завершённый live baseline v2 проверяется отдельно через `--fixtures`, как описано ниже.

## Зафиксированные проверки SP-T-01

- PHP 8.4.19, Composer 2.9.5, donor Laravel 13.38.0 и PHPUnit 12.5.40 из lockfile.
- 31 исходный тест: **31 passed, 71 assertions**; ожидания не менялись.
- Первый запуск выявил отсутствующий compiled-view cache path; после создания только runtime-каталогов весь исходный набор прошёл. Изменений кода донора не потребовалось.
- Harness selftest: 14 checks (нормализация, различение object/list, редактирование секретов, replay, mismatch/missing/exhaustion, отклонение неизвестного provenance, запрет перезаписи/live).
- Изолированная генерация 41 manifest entries, 20 prompts и replay; повторная генерация совпала побайтово после нормализации.
- В SP-T-01 реальных LLM/mail/Telegram/webhook отправок: 0. Это результат исходного offline-этапа, а не счётчик последующего операторского SP-T-03. UI не затрагивается, screenshots к этому этапу неприменимы.

## Операторский этап SP-T-03 (v2)

31.10.2026 пользователь отдельно разрешил реальные AI-запросы для SP-T-03 и установил общий лимит **50 USD на задачу**. Разрешение не распространяется на письма, Telegram, webhooks и другие внешние отправки. Отдельный provider-side лимит ключа не установлен; технический учёт ведётся локально для всей задачи, а не заново для каждого run.

Операторский кандидат `.local/baseline/capture-live-11` завершён: **41 recorded case** (29 persona + 12 manual), **824 сохранённых ответа**, synthetic/missing = 0. Каждый case прошёл два fresh offline replay, всего 82; независимый `verify.py --fixtures=.local/baseline/capture-live-11 --require-recorded --prototype=.local/prototype` завершился с exit 0. Manifest SHA256: `ddeb6252e7345cb6c1c45992dd0b2c4261aac338d05bdaf8c3049e192ee53796`.

Это проверенная запись работы прототипа на моделируемых диалогах, а не доказательство качества продаж или эквивалентности нового движка. Client personas играла реальная LLM-симуляция; ручные случаи используют authored inputs. Seller, extractor и summary также обращались к реальным моделям. Synthetic tests остаются отдельным evidence. Суммы, инциденты и точная граница результата приведены в [отчёте SP-T-03](../results/SP-T-03.md).

### Формат и граница доказательства

v2 — отдельный каталог с manifest, пятью version artifacts, case fixtures и последовательными LLM call files; при resume добавляются явно перечисленные lineage archives. Исходный `tests/fixtures/prototype/manifest.json` v1 и его golden fixtures сохранены. Завершённый v2 должен содержать ровно исходные 29 persona ID и 12 manual ID; неполный run не получает финальный manifest.

Для каждого case сохраняются provenance, исходный сценарий и его SHA, слой выполнения, нормализованный trace, список вызовов и receipts двух offline replay. Версии связывают harness, donor sources, config и все prompt templates, включая simulator. В каждом вызове записаны фактический wire body без headers, requested/returned model, provider request ID и реальные usage/cost из receipt. Фиксированный runner seed — `20261031`, часы — `2026-10-31T09:00:00+03:00`; provider seed не передаётся и обозначен `null/not_requested`.

Новый `harness.json` явно указывает формат `{schema_version: 2, hashing: "sha256-utf8-lf-v1", files: {...}}`. Fingerprints кода вычисляются по строгому UTF-8 после единственной нормализации CRLF→LF, поэтому checkout с LF сохраняет те же fingerprints. Сам SHA artifact в `versions` по-прежнему относится к исходным JSON bytes. Исторические flat maps содержат прежние raw SHA и сохраняются побайтово; они не переписываются под новый алгоритм. При resume совместимость неизменённых legacy sources доказывается только точным совпадением raw/LF/CRLF-представления. Во время активного run отдельный raw-byte guard по-прежнему обнаруживает любые изменения кода.

Подробные поля, правила хэширования, routing envelope и ограничения проверки приведены в [verifier-contract.md](verifier-contract.md). SHA подтверждают согласованность сохранённого evidence, но не являются независимой аттестацией провайдера. `equivalence_claim` остаётся `false`. Детерминированность относится к воспроизведению сохранённых ответов; новую генерацию модели нельзя считать детерминированной.

### Как выполняются сценарии

- `capture-case.php` исполняет исходный Orchestrator в изолированной runtime-копии. Persona loop воспроизводит правила `AgentSimulate`, но сама донорская команда не запускается: она печатает и сохраняет сырые диалоги.
- Ручные v2-сценарии подают явно authored клиентские сообщения через реальный Extractor/Orchestrator. Они отличаются от policy-only synthetic extraction v1; оба вида evidence обозначены отдельно.
- `manual.force_close_14` использует 14 нейтральных сообщений «Понял.». Ожидаемые stage=`close_contact` и turns=`14` записаны рядом с фактическими значениями в `trace.expectation_observation`. Несовпадение не исправляется подменой входа или результата и не вызывает автоматическую повторную генерацию.
- В двух scheduler cases LLM участвует только в Orchestrator setup. Затем запускается фактический scheduler с time travel; границы 20/40/80 часов не выдаются за вывод модели. Phase вызовов — `setup`, последующая проверка таймера не делает LLM calls.
- После capture каждый case дважды выполняется в новых offline PHP-процессах. Запросы и ответы сверяются, записи потребляются по порядку полностью, оба trace должны совпасть. Network fallback отсутствует.

### Совместимость tool definitions

В donor request обнаружены два одинаковых описания `send_price`. Транспортный адаптер оставляет первое определение и удаляет только последующие **канонически идентичные** определения с тем же именем. Разные определения одного имени отвергаются до резерва и POST. Это правило не распространяется на `response.tool_calls`: повторные вызовы модели сохраняются полностью.

Исходные donor request/options и IPC body остаются неизменными. В evidence отдельно хранится actual wire body после адаптации; для наблюдённой границы это 9 исходных definitions и 8 переданных. Проверка legacy resume восстанавливает donor body из исходных options, поэтому прежний hash границы не подменяется. Donor source и его fingerprints не изменены; адаптация явно реализована и проверена в broker/recorder/verifier. Сам факт обнаружения дубля не заменяет утраченный HTTP error body старой попытки.

### Изоляция и нормализация

`capture.py` разрешает исполнение только собственной `.local/prototype` и запись нового кандидата только под `.local/baseline` текущего worktree. Runtime не содержит `.env`, использует SQLite `:memory:`, fake Mail/HTTP и array cache/session. В PHP child отключены URL wrappers, curl/socket/mail, CLI OPcache и запуск shell/process helpers. Это задаётся аргументами дочернего процесса, без изменения общего php.ini. Окружение не наследует ключи, proxy, настройки рабочей БД или чужой PHP config.

Единственный разрешённый live transport находится в Python broker. Он работает только с фиксированными OpenRouter endpoints, без proxy, redirect и повторения платного POST. Donor tools создают лишь записи в памяти; почтовой, Telegram или webhook доставки нет.

`ContactNormalizer` заранее резервирует контакты authored inputs, затем согласованно нормализует outgoing wire и incoming result **до обработки донором**. Разные email сохраняют различие как `contact-NNNN@baseline.invalid`; повторная нормализация идемпотентна, конфликт с зарезервированным namespace запрещён. Поэтому live inputs не заявляются побайтово идентичными исходным persona contacts. Подозрительные телефонные номера останавливают capture до сохранения ответа. Это не универсальный DLP: свободный текст и будущий Git-кандидат требуют операторской проверки.

Ранее выданный точный email-псевдоним сохраняется и внутри явно распознанных парных Markdown-обрамлений: двойных звёздочек, обратных кавычек и одинарных кавычек. Неизвестные aliases, совпадения с частью другого адреса и конфликты namespace по-прежнему отклоняются. Это узкое исправление распознавания, а не общий Markdown parser.

В донорском `conditions.yaml` значение `promotions[0].until` — ISO-дата без кавычек. Symfony YAML преобразует её в integer Unix timestamp, а исходный `Knowledge` вставляет его строкой в факт `too_early_response`. Phone guard ошибочно распознавал эти цифры в responder system prompt как телефон и останавливал run до следующего POST. Исправление разрешает только соответствующий числовой span в полном точном rendered fact: проверяются закреплённые SHA knowledge/prompt/PHP sources, путь загруженного `Knowledge`, роль первого сообщения `system`, component `responder`, единственный заголовок фактов и точный byte offset. Донор, дата и переданный wire не изменяются.

Это исключение не распространяется на пользовательские сообщения, provider responses, другие компоненты или перенесённые/добавленные числа. Настоящий `Goal::toArray()` сохраняет в trace только fact IDs; для trace исключение не добавлено. Если модель повторит timestamp в своём ответе, общий phone guard по-прежнему остановит запись. Переход normalizer при resume разрешён только для проверенных пар исходного и нового хэшей, историческое evidence не переписывается.

Ключ читается только broker из игнорируемого task-local файла, не передаётся в argv или PHP child и не записывается в fixtures. Credentials, заголовки авторизации, сырые console dumps и runtime databases в Git не помещаются. Каталог с evidence не перезаписывается.

### Денежный учёт и metadata checkpoints

Все runs используют один canonical `.local/baseline/budget.sqlite` с process lock. До каждого платного POST broker сохраняет консервативный денежный резерв. Он учитывает опубликованные endpoint tariffs, применимые tiers/cache/reasoning, максимальные размеры контекста/ответа и опубликованную BYOK надбавку; routing закреплён, plugins и fallbacks выключены, `provider.max_price` передаётся в запросе. Неизвестные обязательные параметры стоимости приводят к отказу.

После ответа сверяются receipt usage и generation billing metadata. Подтверждённая стоимость и консервативно удержанные суммы учитываются раздельно. Локальный расчёт основан на опубликованных данных; он не является независимой гарантией неизвестных частных BYOK условий или нераскрытого изменения тарифа у провайдера.

Metadata-only checkpoint записывается через exclusive create, flush и fsync **до первого billing GET** в соседний игнорируемый каталог `<ledger>.checkpoints`. В нём только generation ID, хэши, числовые usage/cost и безопасная форма диагностики; нет content, headers или ключа. `replay_eligible=false`, `response_retained=false`: checkpoint не заменяет LLM fixture и не восстанавливает утраченный ответ.

Billing GET может повторяться максимум 21 раз: задержки `0,1,2,4,8,15,30` секунд, затем до 14 задержек по 60 секунд. Новые GET допускаются только в течение 15 минут от начала polling. Это **не абсолютный wall-clock deadline**: уже начавшаяся HTTP-операция может завершиться позже, а socket timeout ограничивает простой чтения. Расширено только ожидание billing metadata; платный POST не повторяется. Неизвестный результат сохраняет резерв и блокирует обычное продолжение до отдельного безопасного учёта.

Лимит выполнения offline child составляет 3600 секунд без времени успешного synchronous broker handler. После передачи оплаченного ответа child получает до 120 секунд бездействия на сохранение через fsync и следующий IPC message; после `complete` ожидается EOF. Такой grace не разрешает новый POST: перед каждым следующим handler повторно проверяется общий execution deadline. Зависший child останавливается, лимит 200 запросов на case сохранён. Ожидание handler регулируется собственными ограничениями broker, а не выдаётся за время исполнения PHP.

### Инцидент первого операторского запроса

Первый операторский POST завершился остановкой `generation_billing_unknown`. До него был зарезервирован верхний предел **9.0426 USD**. Это **не подтверждённое списание**: в исходной записи `calls` сохранены `state=uncertain` и `charged_micros=NULL`. В прежней версии generation ID и response не сохранялись до polling; точную HTTP/schema причину исходной ошибки и ответ восстановить из локального evidence нельзя.

После инцидента добавлены checkpoints и безопасная диагностика. Отдельный conservative-accounting механизм позволяет целиком вычитать такой резерв из доступного task budget, сохраняя исходный uncertain call и не объявляя его расход подтверждённым. Такой вычет уменьшает доступный бюджет, но не является нулевой стоимостью, сбросом лимита или повторным разрешением потратить 50 USD. Подтверждённые списания, conservative deductions и остаток должны быть приведены раздельно в итоговом отчёте.

`inspect_capture.py` выполняет только read-only диагностику исходных calls/checkpoints и не отправляет HTTP. Его показ строки `uncertain` не заменяет суммарный учёт `budget_forfeitures`: для остатка нужно учитывать отдельные подтверждённые conservative deductions. `reconcile.py` — отдельный операторский инструмент; он не воссоздаёт ответ и не запускает AI generation. Во время активного capture учёт не редактируется вручную.

### Утраченный оплаченный ответ и повторные billing stops

У вызова 221 подтверждён денежный расход, но response не был сохранён после сбоя нормализации. Воспроизведён отдельный false positive для известного email alias в Markdown-обрамлении; сам утраченный текст 221 недоступен, поэтому конкретное обрамление в нём не утверждается. Денежный receipt не восстанавливает исходный ответ.

Для этого случая применён отдельный audited orphan disposition и однократная связь `221 → 222`. Вызов 222 — новая оплаченная генерация, а не восстановление или переименование ответа 221. Исходная подтверждённая стоимость 221 сохраняется. Обязательные audit/checkpoint references, связь replacement и аннотация case экспортируются отдельно; общий orphan guard допускает только явно проверенное исключение. Неучтённый paid-but-lost response по-прежнему блокирует продолжение.

Для вызова 696 generation metadata удалось позднее сопоставить с generation ID из durable receipt checkpoint. Проверяются requested model/provider, токены, стоимость, request hash и время получения receipt; schema2 audit явно сохраняет допуск часов 5 секунд. Старые audit с допуском 1 секунда проверяются по своему сохранённому значению. Окно identity не расширяется до времени billing polling. Settlement сохраняется вместе с append-only DB reference и повторно проверяется перед будущими POST. Это подтвердило стоимость, но не восстановило ответ; оформлена отдельная связь `696 → 697` с metadata-only reconciliation artifact.

Полный резерв консервативно учтён для вызовов 1, 24, 25, 468, 469 и 764. У 1/468/469 причина — `generation_billing_unknown`; у 24/25/764 — `http_rejected` (для 764 checkpoint фиксирует HTTP 429). Их uncertain rows и неизвестная фактическая стоимость сохраняются. Ошибка HTTP не считается бесплатным запросом; автоматического POST retry нет.

Финальная read-only сверка: 832 записи физических вызовов, 826 completed и 6 uncertain, полностью покрытых forfeits; неучтённых pending calls нет. В completed входят 824 retained ответа и два утраченных ответа 221/696. Денежные категории различаются:

| Категория | USD |
|---|---:|
| Сумма provider receipts только 824 retained ответов | 3.41465610 |
| Учёт retained ответов в ledger, с округлением вверх по каждому вызову до микродоллара | 3.414876 |
| Подтверждённый учёт утраченных ответов 221 и 696 | 0.012744 |
| Весь подтверждённый учёт 826 completed calls | 3.427620 |
| Conservative forfeits шести uncertain calls — не подтверждённые списания | 29.610759 |
| Всего вычтено из task limit | 33.038379 |
| Доступный остаток лимита 50 USD | 16.961621 |

Фактический итоговый счёт провайдера по шести uncertain вызовам неизвестен; сумма forfeits не подменяет его. Подробные per-call вычеты и ограничения расчёта находятся в отчёте.

### Продолжение после остановки

`--resume-from` читает незавершённого кандидата и создаёт **новый** output. Исходные call files, case fixtures, version artifacts и предыдущая история остаются неизменными. Сохраняется прежний logical run ID; новый output не создаёт новый бюджет.

До первого нового POST проверяются все сохранённые запросы, wire bodies, ответы и provider receipts. Каждый завершённый case дважды исполняется offline. Если ответы уже записаны через fsync, но case fixture не успел появиться, он восстанавливается из этих ответов без нового обращения к модели. Для первого незавершённого case так же воспроизводится весь сохранённый префикс и фиксируются хэши следующего запроса. Это работает и при нулевом префиксе следующего case.

Вызов live broker допускается только для нового продолжения и после точного совпадения этой границы. Проверяется общий набор физических `budget.call_id` по всем cases, а не локальные sequence одного диалога: каждый сохранённый receipt должен соответствовать подтверждённой строке ledger и durable checkpoint generation ID/request hash. Подтверждённый вызов без сохранённого ответа блокирует продолжение, кроме отдельного проверенного audited orphan disposition с единственной допустимой replacement-связью, описанной выше. Запуск без `--resume-from` также не позволяет обойти эту проверку и повторно оплатить ранее выполненные запросы.

Перед новым POST создаётся immutable sidecar `<output>.resume.json` через exclusive create, flush и fsync. В нём lineage и хэш harness текущего кандидата; ключей и response content нет. **Сохраняйте sidecar рядом с незавершённым кандидатом**: он нужен для восстановления после следующего сбоя.

В финальном manifest поле `resume` связывает сохранённый префикс, два offline preflight и архивы исходных пяти artifacts/завершённых case fixtures. Предыдущие уровни сохраняются в `resume.ancestors` и `lineage/ancestors/`. Исторические bytes и версии не заменяются новыми. После нового сбоя можно продолжить из последнего кандидата с его sidecar; отсутствие новых ответов также не стирает прежнюю историю.

`resume` не снимает финансовую блокировку: неизвестный исход POST сначала требует отдельного операторского учёта. Потерянный response не восстанавливается из billing checkpoint. Вызовы с неизвестным исходом автоматически не повторяются.

Пример команды после разрешения операторского этапа и сверки ledger; `recorded-continuation` должен отсутствовать:

```powershell
& $python -X utf8 tools/baseline/capture.py --resume-from=.local/baseline/recorded-partial --output=.local/baseline/recorded-continuation --allow-live-openrouter --acknowledge-pseudonymized-inputs
```

Для проверки восстановления без сети есть трёхэтапная симуляция: completed case + partial next → ещё один partial next → полный synthetic bundle. Она использует настоящий broker с fake HTTP, сохраняет provenance `synthetic` и проверяет отсутствие повторной отправки сохранённого префикса:

```powershell
& $python -X utf8 tools/baseline/capture_tests/resume_simulation.py --output=.local/baseline/resume-synthetic-new
```

`real_llm_calls` в результате успешного resume — число recorded calls итогового bundle, включая сохранённый префикс. Фактические POST всех попыток, подтверждённую стоимость, conservative deductions и остаток сверяют отдельно по task ledger.

### Проверки и запуск v2

Используются те же `$php`, `$python` и `$offline`, что определены выше. Все output/temporary directories должны быть новыми.

```powershell
# Только offline/fake, без ключа и без сетевых отправок.
& $php @offline tools/baseline/capture_tests/roundtrip.php --prototype=.local/prototype --output=.local/roundtrip-new
& $python -X utf8 -m unittest discover -s tools/baseline/tests -p 'test_*.py' -v
& $python -X utf8 -m unittest discover -s tools/baseline/capture_tests -p 'test_*.py' -v
& $python -X utf8 tools/baseline/capture.py --test-case=all --output=.local/baseline/synthetic-new

# Read-only диагностика accounting; не делает HTTP и не разблокирует ledger.
& $python -X utf8 tools/baseline/inspect_capture.py
```

`--test-case` всегда создаёт synthetic evidence. Такие результаты не копируются в recorded baseline и не проходят recorded provenance gate.

Следующая команда относится **только к отдельно разрешённому операторскому этапу**, после проверки доступа, task budget и отсутствия неучтённых pending calls. Ключ остаётся в игнорируемом файле, значение не указывается в команде:

```powershell
& $python -X utf8 tools/baseline/capture.py --allow-live-openrouter --acknowledge-pseudonymized-inputs --output=.local/baseline/recorded-new
```

Успешный capture сам выполняет два offline replay каждого case. Затем готовый кандидат проверяется без сети:

```powershell
& $python -X utf8 tools/baseline/verify.py --fixtures=.local/baseline/recorded-new --require-recorded --prototype=.local/prototype
```

Проверенный итоговый кандидат можно сверить без новой генерации:

```powershell
& $python -X utf8 tools/baseline/verify.py --fixtures=.local/baseline/capture-live-11 --require-recorded --prototype=.local/prototype
```

В retained evidence: 341 responder, 220 simulator, 238 extractor и 25 summary calls. Simulator использовал `anthropic/claude-haiku-5.5`, responder — `anthropic/claude-sonnet-5.5`, extractor/summary — `google/gemini-2.5-flash`. Вызовов phase=`setup` — 2, остальные 822 — `dialogue`. Ни один результат не является записью переписки с реальным покупателем. Mail/Telegram/webhook transport не включался; `send_price` и другие donor tools выполнялись в изолированном runtime.

Для отдельного повторного исполнения уже сохранённого case можно вызвать PHP runner с тем же run ID из manifest. Это read-only replay без ключа; пропущенная или изменённая запись вызывает отказ:

```powershell
& $php @offline tools/baseline/capture-case.php --prototype=.local/prototype --mode=replay --case=manual.followup_40h --output=.local/baseline/recorded-new --run-id=RUN_ID_FROM_MANIFEST
```

Verifier проверяет структуру и receipts replay, сам Orchestrator не запускает и task ledger не читает. Неполный run, synthetic data, изменённые source/harness hashes или неиспользованные записи не проходят recorded verification. Отдельные runtime guards блокируют неучтённый неопределённый исход платного запроса; conservative forfeits учитываются отдельно от confirmed charges. До переноса в Git отдельно проверяются свободный текст, provenance и отсутствие приватных runtime-файлов.
