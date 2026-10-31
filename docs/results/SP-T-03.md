# SP-T-03 — операторская запись baseline прототипа

Работа ведётся в ветке `codex/sp-t-03` и отдельном worktree. Scope: `docs/baseline/`, `tools/baseline/`, `tests/fixtures/prototype/` и этот отчёт. Основной checkout, чужие worktree и read-only donor не изменялись.

**Операторская запись завершена:** 41 recorded case, 824 retained ответа, два fresh offline replay каждого case. Независимый строгий verifier завершился с exit 0. Это baseline поведения прототипа на моделируемых диалогах; `equivalence_claim=false`. Исполнитель создаёт commit и передаёт задачу в review; интеграция выполняется отдельно.

## Реализовано

- Отдельный v2 recorder и строгий verifier с исходным набором 29 persona ID и 12 manual ID. v1 fixtures, ожидания, `bootstrap.php` и `FixtureClient.php` сохранены.
- Исходный Orchestrator работает в отдельном PHP child через IPC. Сырые console dumps команды `AgentSimulate` не запускаются и не сохраняются.
- Реальные donor config/prompts/source fingerprints, фактические requested/returned models, provider receipts, usage/cost и SHA запросов/ответов связываются с каждым case.
- Runner seed `20261031` и фиксированные часы записаны явно. Provider seed не запрашивается; воспроизводимость новой генерации модели не обещается.
- Каждый успешно записанный case дважды выполняется в новых offline PHP-процессах. Проверяются полный расход последовательности fixtures, точность запросов, совпадение trace и отсутствие сети.
- Различные email заменяются устойчивыми валидными псевдонимами до wire/runtime, включая structured tool arguments. Узкое распознавание ранее выданного точного alias в парных Markdown-обрамлениях сохраняет защиту от неизвестных и colliding aliases. Подозрительные телефоны останавливают запись; единственное source-derived исключение для timestamp описано ниже.
- Для scheduler cases реальные LLM calls относятся только к setup. Границы 20/40/80 часов проверяет сам scheduler; synthetic T-01 traces не переименовываются в recorded.
- Для `manual.force_close_14` заранее выбраны 14 нейтральных сообщений «Понял.». Expected stage/turns и фактический результат сохраняются отдельно; несовпадение не скрывается повторной генерацией или правкой golden evidence.
- Единый task-wide budget ledger, резервы до каждого POST, запрет автоматического повторения платного запроса, строгое сопоставление receipts и безопасные metadata-only checkpoints.
- Отдельные read-only диагностика и операторский учёт неопределённого вызова. Conservative deductions не выдаются за подтверждённый расход. Для подтверждённого paid-but-lost ответа предусмотрены отдельный audited orphan disposition, однократная replacement-связь и экспорт проверяемых metadata references.
- Generalized resume через границы cases: два offline preflight, точное совпадение первого нового запроса, пропуск live для завершённых cases, восстановление отсутствующего case fixture из durable responses. Общий ledger guard учитывает все saved calls и блокирует paid-but-lost response/fresh-run обход.
- Immutable resume sidecar создаётся до нового POST; многоуровневая lineage сохраняет исходные harness и завершённые case fixtures побайтово, включая повторную остановку после восстановления.
- Переносимые fingerprints новых harness используют явный UTF-8/LF формат; исторические raw SHA и artifact bytes сохраняются. Активный run защищён отдельной проверкой raw bytes.
- Stable-first adapter удаляет только идентичные повторные tool definitions из physical wire. Исходные donor request/options и response tool calls не изменяются; конфликтующие определения одного имени отвергаются до POST.

Описание и команды: [README](../baseline/README.md). Формат evidence и пределы verifier: [verifier-contract.md](../baseline/verifier-contract.md).

## Разрешение и изоляция

31.10.2026 пользователь отдельно разрешил реальные AI-запросы SP-T-03 с общим лимитом **50 USD на задачу**. Ключ хранится только в игнорируемом runtime; его значение не включается в Git, аргументы команд, логи или отчёт.

Разрешение не включает реальные письма, Telegram, webhooks и прочие внешние отправки. Единственный live transport — контролируемый OpenRouter broker; redirect, proxy, plugins и model fallbacks запрещены. В PHP child отключены network/mail/process helpers и CLI OPcache, HTTP/Mail подменены, env с ключами и рабочей БД не наследуется. Общий php.ini не изменяется.

Runtime-копия донора и её locked dependencies находятся в собственной `.local/prototype`, без `.env`. Donor execution использует SQLite `:memory:`; бюджет — отдельный task-local SQLite ledger. Общая рабочая БД не используется для выполнения сценариев.

Output создаётся только в новом каталоге текущего worktree. Кандидат не перезаписывается; неполный run не получает финальный manifest. Перенос в tracked recorded fixtures допустим только после проверки завершённого evidence.

## Проверки инфраструктуры

Ниже — зафиксированные результаты offline-подготовки, а не число успешных реальных диалогов.

| Проверка | Зафиксированный результат |
|---|---|
| Исходные donor tests, зафиксированные в SP-T-01 | 31 passed, 71 assertions; ожидания не изменялись |
| Legacy harness selftest | 14 checks passed |
| Legacy v1 verifier | 41 case IDs, 20 prompt snapshots; 0 recorded, 12 synthetic, 29 missing |
| ContactNormalizer / RecordedClient roundtrip | 115 checks passed, два точных replay; контакты и их обрамления, JSON/tool arguments, float precision, secrets, namespaces, overwrite/missing/mismatch и targeted 9→8 tool adapter/replay |
| Source-derived date fact | 27 PHP checks passed и два fake replay; exact wire, source pins, роли/компоненты/offset, чужие числа, provider response и trace остаются под строгой проверкой |
| Operator, resume, generalized crash guards, portable hashes и child deadline | 47 tests passed, включая 10 fake-clock deadline tests; настоящий broker с fake HTTP, completed/partial cases, receipt→ledger/checkpoint binding, no overwrite/no duplicate POST, crash windows и pending-reply grace |
| Broker после checkpoint/accounting/transport fixes | 61 tests passed; durable reserve/checkpoint, billing readiness в расширенном GET окне, deadline/attempt cap, crash windows, no POST retry, секрет в generation ID и изоляция ledger |
| Reconciliation / conservative accounting | 47 offline tests passed; checkpoint-bound settlement, immutable references, CAS, prior forfeits, BYOK/token/cost binding, допуск времени 1/5 секунд и отрицательные границы |
| V2 verifier и routing/lineage/portable/orphan interop | 117 tests passed; synthetic helper profile явный, production default strict recorded, settlement metadata и исторический допуск времени проверяются независимо |
| Orphan disposition/replacement accounting | 20 offline tests passed; старый normalization loss и новый billing loss, сохранение charge, точный settlement reference, privacy и replacement |
| Полный synthetic orphan run с двумя resume | 41 case, 116 сохранённых ответов из 117 fake POST, включая один settled-lost reply и отдельный replacement; 82 итоговых fresh PHP offline replay, source A/B неизменны, реальной сети нет |

Последняя полная трёхэтапная synthetic симуляция выполнена на финальных capture/orphan/verifier/reconcile/broker sources. Проверенный artifact: `.local/baseline/independent-billing-full41-984b9ce1027e463485460efc3ba74a1a/final`. Сохранены 116 ответов из 117 fake POST, два уровня resume, один explicit settled-lost reply и отдельный replacement. Source bytes и исходная charge неизменны; реальной сети нет. Строгий recorded verifier корректно отклоняет synthetic provenance. Старые bundles и их хэши не переписывались.

Независимый итоговый набор из **246 тестов PASS** — это повтор 47 reconciliation + 61 broker + 20 orphan + 117 verifier и один дополнительный mixed-old/new-orphan test; эти проверки пересекаются с таблицей, их не нужно суммировать как отдельное покрытие. Mixed test прошёл весь путь settlement → disposition → replacement → actual capture export → verifier, проверив неизменность старого audit и отсутствие приватных полей. Дополнительно ранее прошли два temp-DB теста: повреждение audit или receipt checkpoint после создания broker блокирует следующий POST.

После финальных изменений повторно прошли все 47 capture-тестов и 245 тестов verifier и учёта. Изменение skew также покрыто independent mixed test, полным fake run и строгой проверкой real bundle. Все child deadline tests сохраняют idle120, limit200 и запрет нового handler после истечения active deadline.

Переносимость harness ранее проверена на LF-only копии без изменения historical SHA. Полный synthetic run не содержит adapted tool calls; targeted PHP 9→8/replay и broker/verifier 9→8→9 тесты проверяют именно эту ветку. Восстановление real prefix многократно выполнялось fresh offline без handler и без сетевого fallback; финальные receipts 82 replay всех 41 cases подтверждены строгим verifier.

Независимый финальный privacy/safety scan проверил 1025 JSON-файлов (50,627,257 bytes). Секреты, credential fields, неотредактированные ASCII/Unicode email и runtime-файлы не обнаружены; найдены только детерминированные aliases. Четыре phone-like вхождения в content проверены offline PHP как точные pinned source-date spans; неизвестных контактов не обнаружено. Эта проверка не превращает sanitizer в универсальный DLP и не заменяет оценку качества текстов.

UI в scope отсутствует, screenshots и проверки размеров экрана неприменимы.

## Инцидент и денежный учёт

Первый операторский запрос остановился с `generation_billing_unknown`. До POST был создан резерв **9.0426 USD**. У исходной записи `calls` остались `state=uncertain` и `charged_micros=NULL`: **9.0426 USD не является подтверждённым списанием**.

Старая версия не сохраняла generation ID/receipt до billing polling и подавляла подробности неуспешных metadata ответов. Поэтому локально нельзя восстановить первый ответ, установить точный исходный HTTP/schema ответ или приписать этому вызову выдуманную стоимость. Read-only проверка подтвердила отсутствие checkpoint и неизменность байтов ledger до/после диагностики.

Исправление сохраняет только provider generation ID, хэши, числовые usage/cost и безопасные HTTP status/shape codes через exclusive create, flush и fsync до первого billing GET. Content, headers и ключ не сохраняются; `replay_eligible=false`. Проверка отражённого ключа выполняется до записи checkpoint.

После повторных `generation_billing_unknown` расширено только ожидание billing metadata: максимум 21 GET, задержки `0,1,2,4,8,15,30` секунд, затем до 14 раз по 60 секунд. Допуск новых GET ограничен 15 минутами. Это не абсолютный deadline уже начатой HTTP-операции; socket timeout ограничивает простой чтения. Платный POST автоматически не повторяется.

Время успешного synchronous handler исключено из 3600-секундного execution budget offline child. После отправки оплаченного ответа сохранён отдельный idle grace до 120 секунд для child fsync и следующего IPC message/завершения. Истёкший execution budget запрещает новый handler до платного запроса. Idle guard и предел 200 запросов на case сохранены; зависший child останавливается. Проверены ожидания handler дольше часа и сохранение ответа при почти истёкшем execution deadline.

Отдельный conservative forfeit позволяет полностью учесть неопределённый резерв как вычет из доступного лимита, не меняя исходный uncertain call на confirmed и не обнуляя предыдущие запросы. Такой вычет должен показываться отдельно от фактических provider charges. Ни accounting, ни checkpoint не восстанавливают утраченный ответ.

`inspect_capture.py` показывает исходные calls/checkpoints и не заменяет итоговый расчёт с `budget_forfeitures`. Итоговые суммы ниже получены отдельными read-only SELECT в SQLite (`mode=ro`, `query_only`, read transaction); SHA байтов ledger до и после чтения совпал.

### Ответ 221 и replacement

Provider charge вызова 221 подтверждена, но ответ утрачен при нормализации до durable call file. В synthetic regression воспроизведён false positive известного alias внутри парных Markdown-обрамлений; утраченный response не позволяет утверждать, какое именно обрамление было в нём. Исправление признаёт только ранее выданную точную identity и не ослабляет unknown/collision guard.

Применён отдельный audited orphan disposition со связью `221 → 222`. Исходная charge 221 не удаляется и не заменяется нулём; 222 является новой оплаченной генерацией. Audit, metadata checkpoint, replacement reference и аннотация case сохраняются отдельно. Утраченный ответ не воссоздаётся и не выдаётся за recorded content. Остальные paid-but-lost ответы без такого проверенного основания по-прежнему блокируют продолжение.

### Ложный phone match в frozen source fact

Остановка на responder system prompt произошла до следующего POST: Symfony YAML разобрал исходную ISO-дату `promotions[0].until` без кавычек как Unix timestamp, а donor `Knowledge` вставил его в факт `too_early_response`. Phone regex принял цифры за телефон.

Добавлено исключение только для exact source-derived span полного rendered fact в первом `system` сообщении component `responder`. Зафиксированы source SHA, Reflection path `Knowledge`, заголовок/единственность строки и byte offset. Donor sources, дата и wire сохранены; historical evidence не переписывается, resume принимает только reviewed normalizer hash transitions. User/provider content, соседние или перенесённые числа и другие компоненты исключение не наследуют. `Goal::toArray()` пишет fact IDs, поэтому trace остаётся под обычным guard без специального исключения. Повтор timestamp моделью в response всё ещё останавливает запись.

### Settlement 696 и второй утраченный ответ

У 696 polling первоначально завершился `generation_billing_unknown`; последующий metadata GET сопоставлен с generation ID из durable checkpoint, а не с произвольно выбранным Logs ID. Проверены model/provider, токены, стоимость и времена. Provider timestamp был раньше локального начала на 1.068 секунды; фиксированный допуск schema2 расширен с 1 до 5 секунд, не до 15-минутного polling окна. Новые audit явно сохраняют 5 секунд, старые читаются со своим допуском 1 секунда; границы ±5 секунд и отказ за пределом на 1 микросекунду проверены тестами.

Стоимость 696 подтверждена и учтена как **0.010816 USD** с округлением вверх до микродоллара. Response остался утраченным. Отдельный audited disposition связывает `696 → 697`; 697 — новая оплаченная генерация. Immutable settlement reference повторно проверяется broker перед POST и экспортируется как sanitized metadata без key fingerprint, ledger snapshot hash или содержимого ответа. Это не независимая аттестация request hash провайдером.

### Conservative forfeits

Для шести uncertain calls консервативно вычтен полный резерв. Rows не переведены в confirmed, фактическая стоимость этих вызовов неизвестна. Для 764 безопасный checkpoint фиксирует HTTP 429; ошибку HTTP не считали бесплатной и POST автоматически не повторяли.

| Call ID | Причина в ledger | Conservative deduction, USD |
|---:|---|---:|
| 1 | generation_billing_unknown | 9.042600 |
| 24 | http_rejected | 9.042600 |
| 25 | http_rejected | 9.042600 |
| 468 | generation_billing_unknown | 0.827653 |
| 469 | generation_billing_unknown | 0.827653 |
| 764 | http_rejected | 0.827653 |
| **Всего** | **Не подтверждённые provider charges** | **29.610759** |

## Итог реального операторского прогона

- Проверенный кандидат: `.local/baseline/capture-live-11`; logical run ID `capture_20261031T104157_67845ea5`.
- Manifest SHA256: `ddeb6252e7345cb6c1c45992dd0b2c4261aac338d05bdaf8c3049e192ee53796`.
- **41 recorded case:** 29 persona + 12 manual, synthetic 0, missing 0.
- **824 retained calls**, 2,409,250 tokens в сохранённых provider receipts; 822 dialogue calls и 2 setup calls.
- Два fresh offline replay каждого case, **82 replay**; сохранённые trace hashes, consumption/exhaustion и network_calls=0 проверены независимым verifier. Сам verifier не исполняет Orchestrator повторно.
- `verify.py --fixtures=.local/baseline/capture-live-11 --prototype=.local/prototype --require-recorded`: **PASS, exit 0**.
- История содержит 9 resume levels. Экспортированы два abandoned paid calls с отдельными replacement-связями `221 → 222` и `696 → 697`.
- В task ledger 832 записи физических вызовов: 826 completed (824 retained + 2 lost) и 6 uncertain с полным conservative forfeit. Неучтённых pending calls нет, `budget.blocked=NULL`.

### Модели и смысл симуляции

| Компонент | Requested model | Retained calls |
|---|---|---:|
| Responder продавца | anthropic/claude-sonnet-5.5 | 341 |
| Simulator клиентских персон | anthropic/claude-haiku-5.5 | 220 |
| Extractor | google/gemini-2.5-flash | 238 |
| Summary | google/gemini-2.5-flash | 25 |

Это реальные provider responses, но клиенты — LLM-симулированные persona, а не покупатели. Manual inputs написаны заранее. Отдельные synthetic tests использовали fake HTTP и никогда не выдаются за recorded baseline. В scheduler cases модель создаёт только setup; таймеры выполняет настоящий donor scheduler с time travel.

Adapter сохраняет donor request/options, удаляет только идентичные duplicate tool definitions из physical wire, задаёт фиксированного provider, отключает fallbacks/plugins/streaming и включает usage. Response tool calls не дедуплицируются. Email inputs псевдонимизированы до отправки; clock/runner seed заморожены, provider seed не задаётся. Реальные письма, Telegram, webhooks и иные внешние отправки не выполнялись: donor tools работали только в изолированном runtime.

`manual.force_close_14` сохранил фактические stage=`close_contact`, turns=`14`, совпавшие с явно записанным ожиданием. Наличие 41 recorded cases не означает автоматический PASS всех бизнес-ожиданий персон: полная семантическая оценка качества продаж и текстов не подменяется проверкой replay.

### Денежный итог

| Категория | USD | Основание |
|---|---:|---|
| Сумма provider receipts 824 retained calls | 3.41465610 | Строгий verifier, исходные usage.cost |
| Ledger charge этих retained calls | 3.414876 | Округление вверх по каждому вызову до микродоллара; отличается от суммы дробных receipt costs |
| Утраченный completed call 221 | 0.001928 | Подтверждённый ledger charge, ответа в replay нет |
| Утраченный completed call 696 | 0.010816 | Подтверждённый checkpoint settlement, ответа в replay нет |
| **Весь подтверждённый учёт 826 completed calls** | **3.427620** | Retained + lost; включает консервативное округление |
| **Conservative forfeits 6 uncertain calls** | **29.610759** | Полные верхние резервы, не подтверждённые списания |
| **Всего вычтено из task limit** | **33.038379** | Confirmed ledger charges + forfeits |
| **Остаток лимита 50 USD** | **16.961621** | 50 − 33.038379; неучтённых pending reserves нет |

Эти суммы не являются итоговым счётом OpenRouter: фактические charges шести uncertain calls неизвестны, а ledger округляет подтверждённые costs вверх. Замена неопределённой стоимости полным резервом уменьшила доступный лимит без его сброса. Ни один forfeit не возвращён в доступный бюджет.

Исполнитель создаёт commit и выполняет submit в review; merge в main и отметка `done` выполняются интегратором отдельно.

## Ограничения

- 41 recorded cases и успешный replay подтверждают наличие и воспроизводимость evidence; они не доказывают качество реального LLM, успешность продаж или эквивалентность будущего переноса. Synthetic tests имеют отдельную provenance.
- Нормализованные контакты сохраняют различимость, но inputs не побайтово идентичны исходным persona contacts; свободный текст требует проверки перед Git.
- SHA и verifier доказывают внутреннюю согласованность evidence, а не независимую provider attestation. `equivalence_claim=false` сохраняется.
- Provider-side лимит ключа не установлен. Локальный резерв основан на опубликованных endpoint tariffs, tiers/cache/reasoning и консервативном BYOK допуске; неизвестные частные BYOK условия и нераскрытые изменения тарифов не получают фиктивной гарантии. Проверка не заменяет независимый итоговый счёт провайдера.
- Утраченные ответы не заменяются synthetic данными и не восстанавливаются одной записью денежного учёта. Audited replacement — отдельная генерация с отдельной стоимостью и provenance.
- Source-derived timestamp exception намеренно ограничен frozen responder system fact; если provider повторит эти цифры в response, phone guard остаётся fail-closed.
- 15 минут ограничивают допуск billing GET, а не длительность уже выполняющегося HTTP чтения; child idle/execution guards и broker polling — разные ограничения.
- Исполнитель передаёт результат только в review. Merge в main и отметку done выполняет интегратор.
