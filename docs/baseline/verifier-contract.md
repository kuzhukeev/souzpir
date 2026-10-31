# SP-T-03: контракт проверки recorded baseline

Проверка `tools/baseline/verify.py` работает через Python standard library, не загружает PHP-приложение, не читает рабочую БД и не обращается к провайдеру. Запуск verifier не разрешает live capture. Полномочия и бюджет операторского этапа проверяются отдельно.

## Два независимых формата

- `schema_version=1`: исходный T-01 остаётся неизменным. 41 case ID — 29 персон `missing` и 12 ручных traces `synthetic`; 20 rendered prompt snapshots, отдельные 3 synthetic LLM calls. Обычная проверка подтверждает доступное evidence, `--require-recorded` обязана отказать. Нельзя перемаркировать v1 как recorded.
- `schema_version=2`: отдельный завершённый bundle с ровно 41 recorded case. Незавершённый capture не получает финальный manifest и не засчитывается. Изменять v1 или его ожидания ради успешной проверки запрещено.

Проверка набора ID закреплена в verifier: 29 исходных персон и 12 ручных кейсов. Количество само по себе не позволяет заменить сценарии. Сохранённые T-01 manifest и inventory из 71 fingerprint проверяются по закреплённым SHA-256; при `--prototype` также проверяются реальные файлы read-only донора.

## Manifest v2

Обязательные поля:

- `schema_version: 2`, `run_id`, `captured_at` с часовым поясом, `equivalence_claim: false`.
- `versions`: `harness_sha256`, `prototype_source_sha256`, `config_sha256`, `prompts_sha256`, `normalization: contacts-v1`.
- `artifacts`: `baseline_manifest`, `source_fingerprints`, `config`, `prompts`, `harness`. Каждый — `{path, sha256}`. Версионные SHA соответствуют байтам соответствующего JSON-файла.
- `config` — безопасный снимок фактической конфигурации и фиксированных часов. `prompts` — полный map относительных donor paths всех prompt views (включая simulator) в SHA. `harness` — явный UTF8/LF fingerprint wrapper, описанный ниже; исторические flat maps сохраняют raw SHA. Обязательны bootstrap.php, FixtureClient.php, ContactNormalizer.php, RecordedClient.php, capture-case.php и capture.py. Файлы harness сверяются с текущим checkout по объявленному алгоритму.
- `cases`: `{id, kind, provenance: recorded, fixture, fixture_sha256, execution_layer}` для каждого исходного ID.
- `coverage`: `{total_cases:41, personas:29, manual:12, recorded_real_llm:41, synthetic:0, missing:0}`.

Все файлы bundle должны быть объявлены: manifest, пять artifacts, case fixtures и LLM records. Неиспользованные файлы, включая лишний call, отклоняются. Логи, временные состояния и незавершённые ответы не входят в финальный bundle.

## Case fixture v2

- `schema_version`, `case_id`, `kind`, `provenance` совпадают с manifest.
- `capture`: `{run_id, method:live|import, provider:openrouter, captured_at, seed, inputs_pseudonymized:true, byte_identical_persona_inputs:false}`.
- `seed`: `{runner:20261031, requested:null, provider_support:not_requested}`. Это seed локального runner. Он не заявляет поддержку seed провайдером или воспроизводимость новой генерации модели. Донор не передаёт provider seed.
- `versions` полностью совпадает с manifest.
- `execution`: `{layer, input_provenance, scenario, scenario_sha256}`. `scenario.case_id` связывает входы с конкретным кейсом. Для personas layer — `donor_orchestrator_persona`, входы — `donor_persona`; для ручных сценариев — `donor_orchestrator_scripted` и `authored_script`.
- `trace` — непустой наблюдённый результат исходного runtime после оговорённой нормализации.
- `llm_calls`: непустой последовательный список `{sequence,path,sha256,phase}`. Номера начинаются с 1, без пропусков и дублей, имя файла — `0001.json` и далее. Путь и provider receipt ID нельзя переиспользовать между кейсами.
- `replay`: `{passes:2, trace_sha256, run_trace_sha256:[hash,hash], consumed_sequences:[[1..N],[1..N]], network_calls:0}`. Оба хэша равны хэшу сохранённого trace. Каждый replay потребляет весь набор ровно один раз.

Для `manual.followup_20h` и `manual.followup_40h` layer — `donor_orchestrator_setup_and_scheduler`, LLM phase только `setup`. Живой Orchestrator создаёт исходное состояние; таймер проверяется фактическим deterministic scheduler, а не фиктивным LLM-вызовом. `scenario.scheduler_hours` и `trace.scheduler` сохраняют границы `[19.99,20]` / `[20,39.99,40,79.99,80]`; `followups_sent` — `[0,1]` / `[1,1,2,2,2]`, последний outcome на 80 часах — `stale`. `trace.setup` также обязателен. Это честное различие execution layers; synthetic timer traces T-01 сохраняются отдельно.

## LLM record и соответствие wire

Call сохраняет совместимую оболочку `schema_version:1`, но `provenance:recorded`, `provider:openrouter`:

- `request`, `request_sha256` — нормализованный запрос интерфейса LlmClient.
- `response`, `response_sha256` — сериализованный LlmResult, включая полный безопасный `raw` receipt.
- `evidence`: run/case/sequence/phase/time, HTTP 200, provider request ID, фактическая модель, реальные usage/cost, `request_body`, `request_body_sha256`, `seed_requested:null`.

Все внутренние SHA пересчитываются по каноническому JSON, совместимому с `FixtureClient::json`: сортировка ключей, четыре пробела, LF, Unicode/slashes, различение object/list и int/float, PHP scientific float representation. Внешние file SHA проверяются по байтам.

`request_body` — безопасное точное представление отправленного JSON body, без authorization headers. Verifier сопоставляет модель, сообщения и параметры. Специальная обработка Anthropic сохраняет поведение донора: вместо response_format добавляется user-инструкция с JSON schema; для остальных моделей structured format остаётся в body с provider.require_parameters. Поверх donor body допускается только конкретная broker envelope: `stream:false`, `plugins:[]`, `provider.only/order` — один утверждённый провайдер (`anthropic` или `google-ai-studio` согласно модели), `allow_fallbacks:false`, `require_parameters` сохраняет donor semantics. `max_price` содержит только prompt/completion/request с конечными неотрицательными JSON numbers; технические верхние границы выводятся из $50 на запрос и единицы «за миллион токенов», а не объявляются тарифами. Фактические цены и консервативный денежный резерв рассчитывает broker по свежим endpoint metadata; verifier не выдаёт одну лишь форму max_price за независимую проверку действующего тарифа. Любые дополнительные routing/semantic fields отклоняются.

Метаданные receipt сверяются с response.raw: уникальный ID, model, tokens, cost, content и tool calls. Пересчитанный наружный SHA не скрывает рассогласованность внутреннего trail.

Usage сохраняет целые prompt/completion/total tokens; total равен сумме. `cost_usd` — десятичная строка из фактического provider receipt, не придуманный тариф. Error response не может стать успешным recorded call.

## Продолжение незавершённого capture

Опциональный `manifest.resume` имеет `schema_version:1`. Новый output сохраняет исходный логический `run_id`: иначе изменились бы `Lead.external_id` и детерминированные входы. Исходный каталог, ранее полученные calls и исторические case fixtures не переписываются. Поддерживаются ведущие завершённые кейсы и первый незавершённый кейс; при наличии всех 41 завершённых кейсов новые live-запросы не требуются.

- `source_run_id` совпадает с manifest.run_id; у prefix и новых calls остаётся тот же ID. Provenance и provider receipts не перемаркируются.
- `source_artifacts` содержит пять `{path,sha256}` исходного capture, явно объявленных внутри `lineage/source/`. Их файловые SHA пересчитываются. Baseline inventory, donor fingerprints, config и prompts должны побайтово совпасть с текущими версиями. Исторический harness сохраняется с исходными SHA; его файлы не объявляются совпадающими с новым кодом. Inventory исторического harness должен быть полным и входить в inventory текущего.
- `prefix` — непустой плоский список `{case_id,sequence,path,sha256}` по исходному порядку 41 кейса. В каждом кейсе последовательность начинается с 1, без пропусков и повторов, максимум 200 calls на кейс и 8200 всего. Пути — канонические `llm/<case_id>/<nnnn>.json`, SHA относятся к исходным байтам. Пересчёт наружного case hash не разрешает менять prefix.
- `completed_cases` — список `{case_id,path,sha256}` для действительно существовавших исторических case fixtures. Они сохраняются в `lineage/source/cases/<case_id>.json`, проверяются по исходным source versions, recorded provenance, logical run ID, trace и точному набору calls. Исторические fixtures не переименовываются в результат нового harness.
- `preflight` содержит `passes:2`, `network_calls:0`, `consumed_sequences:{case_id:[1..N]}`, `completed_trace_sha256:{case_id:hash}`, `next_requests:{frontier_case_id:{request_sha256,request_body_sha256}}` и `pass_sha256:[hash,hash]`. Hash обоих проходов равен `digest_value({consumed_sequences,next_requests,completed_trace_sha256})` по PHP-совместимому каноническому JSON.
- Завершённые trace receipts должны соответствовать ведущим кейсам в исходном порядке. `consumed_sequences` включает все завершённые кейсы и frontier, даже если для него ещё нет записей (`[]`). `next_requests` содержит только frontier; при 41 завершённом кейсе это `{}`.
- Если процесс остановился после сохранения последнего call, но до case fixture, два свежих offline прогона могут доказать завершение кейса. Тогда его trace hash включается в `completed_trace_sha256`, но исходный case fixture не выдумывается и `completed_cases` не получает несуществующую запись. Финальный trace и набор calls обязаны совпасть с доказанным результатом.
- `request_body_sha256` на границе относится к donor body до broker routing envelope. Verifier сопоставляет его с фактическим первым новым call после удаления только разрешённой broker envelope; provider.require_parameters сохраняется для non-Anthropic structured output. Нормализованный request hash также обязан совпасть.

Для повторного продолжения `resume.ancestors` — список таких же объектов без вложенного поля ancestors, от самого раннего к последнему. Максимум 200 уровней вместе с текущим. Исторические artifacts и case fixtures каждого уровня находятся в `lineage/ancestors/0001/`, `0002/` и далее. Prefix каждого уровня является неизменённым началом следующего; старые completed trace hashes не меняются, а каждая граница `N+1` сверяется с сохранённым call. Если новых ответов не появилось, равные prefix допустимы только при совпадении всех трёх semantic preflight maps. Это сохраняет lineage даже при повторном сбое до первой новой записи. Верхний source_artifacts описывает непосредственный источник в `lineage/source/`.

Runner сохраняет immutable sidecar вне output до первого нового POST, чтобы при следующем прерывании восстановить всю предыдущую цепочку и проверить её привязку к harness исходного output. Verifier проверяет объявленную финальную цепочку: удаление всех согласованных локальных свидетельств владельцем файлов не может быть обнаружено без внешней аттестации. Оставшиеся необъявленными ancestor-файлы отклоняются как orphan.

Полный resumed bundle по-прежнему требует все 41 recorded case, два полных offline replay каждого кейса, каждый объявленный call и отсутствие orphan files. Два prefix preflight не заменяют финальные replay. Сохранённые receipts доказывают согласованность, а не независимое наблюдение выполнения: runner обязан провести оба свежих offline прогона до любых новых POST, сопоставить union всех сохранённых calls с task ledger и остановиться при completed charge без durable response. Неопределённый предыдущий HTTP-вызов не разрешается автоматически: финансовая блокировка и операторское решение остаются отдельными условиями продолжения.

## Безопасность файлов и данных

- Только относительные portable paths внутри bundle. Запрещены абсолютные пути, `..`, Windows drive/ADS/backslash, device names, symlinks/junctions и выход за root.
- JSON с повторяющимися ключами, non-finite numbers, неверной схемой и чрезмерным размером отклоняется. Один JSON ограничен 16 MiB.
- Известные credential fields, bearer/API/bot-token patterns и реальные email не допускаются. Для сохранения семантики контактов разрешены только стабильные вымышленные `contact-NNNN@baseline.invalid`.
- Проверка редактирования не является универсальным DLP: телефоны, адреса и свободный текст требуют operator review до помещения в Git. Исходные реальные данные и ключи не печатаются в отчёт verifier.
- Contact normalization применяется согласованно до wire/runtime/replay. Этот baseline не объявляется побайтово идентичным исходным контактным данным донора.

## Граница доказательства

SHA и перекрёстные проверки доказывают внутреннюю согласованность сохранённого audit trail: изменение, пропуск, лишний вызов, несовпадающий response или расход обнаруживаются. Они **не являются независимой аттестацией провайдера**. Умышленно изготовленный согласованный bundle, включая все receipts и hashes, невозможно отличить от настоящего только локальным чтением JSON. Verifier прямо возвращает `provenance_verification: structural_integrity_not_independent_provider_attestation` и `equivalence_claim:false`.

Результат verifier не заменяет наблюдённый операторский capture, разрешение, бюджет и фактическое выполнение двух offline replay. Он проверяет сохранённые replay receipts, сам не запускает Orchestrator. Реальный deterministic replay выполняет recorder harness с запрещённой сетью. Ответы fixture воспроизводимы; повторная новая генерация LLM не обещается.

В исходном read-only donor отсутствуют sim JSON/JSONL/log/CSV/DB записи для импорта. Обычный вывод `AgentSimulate` сохраняет диалог и slim meta, но не полный raw request/response/version trail; одного такого transcript недостаточно для этого контракта.

## Команды проверки

Из task worktree, без ключей и live-режимов:

```powershell
& $taskPython tools/baseline/verify.py --fixtures=tests/fixtures/prototype --prototype='C:/OSPanel/home/souzpir.local/.local/references/laravel-prototype'
& $taskPython tools/baseline/verify.py --fixtures=.local/recorded-candidate --require-recorded --prototype='C:/OSPanel/home/souzpir.local/.local/references/laravel-prototype'
& $taskPython -m unittest discover -s tools/baseline/tests -p 'test_*.py' -v
```

Unit suite строит только явно искусственные временные bundle внутри собственной `.local/baseline-verifier-tests`; они не копируются в golden fixtures и не служат recorded evidence. Проверки включают корректную структуру и намеренные нарушения identity, hashes, provider receipts, replay exhaustion, scheduler boundaries, версий, путей, секретов и seed claims.

Для offline-тестов runner структурный helper `validate_resume` имеет явный `source_provenance="synthetic"`: исторические fixtures обязаны сохранять `synthetic`, `provider: fake IPC broker` и `method: test`. Это не режим CLI и не ослабление `validate_recorded`: production entry point всегда использует `recorded` по умолчанию и отклоняет synthetic ancestry. Такой helper позволяет проверить несколько прерываний без подмены происхождения fake ответов.

## Переносимые fingerprints и узкая адаптация tools

Новые `harness.json` используют `{schema_version:2, hashing:sha256-utf8-lf-v1, files:{relative_path:sha256}}`. Hash исходника вычисляется из strict UTF-8 после единственного преобразования CRLF→LF; lone CR, BOM и остальные bytes не удаляются. Это позволяет Git checkout с LF и локальным CRLF иметь одну версию кода. Неизвестные algorithm/schema отклоняются. `versions.harness_sha256` по-прежнему соответствует сырым bytes immutable JSON artifact. Runtime guard recorder продолжает обнаруживать любые изменения сырых файлов во время capture.

Исторические flat maps имеют прежнюю семантику `sha256-raw-v1`; их artifacts, записанные SHA, case versions и calls не переписываются. Lineage может содержать оба формата. Только resume source guard для неизменённых semantic files разрешает криптографически точное совпадение старого raw SHA с текущими raw/LF/CRLF вариантами; другие изменения кода не разрешаются этим исключением. Два свежих replay по-прежнему обязательны.

В donor `Policy::toolsFor` повторное добавление `send_price` может создать два одинаковых объявления функции. Transport сохраняет первое в исходном порядке только при полном canonical равенстве повторной декларации; одинаковое имя с иным contract отклоняется до отправки. Исходные `request.options.tools` и request hash остаются неизменными, actual wire и его hash содержат unique declarations. Verifier допускает только эту конкретную адаптацию, отвергая переупорядочивание/потерю других tools и conflicting duplicates. Это не объявляется побайтовой эквивалентностью donor wire. `response.tool_calls` не дедуплицируются.

Для проверки старой границы resume `resume_donor_body` после валидации actual wire восстанавливает оригинальные donor declarations, включая одинаковые повторы: сохранённый preflight request body hash не меняется из-за исправления транспорта. Historical evidence остаётся побайтово неизменным.


## Явно утраченный оплаченный ответ

Если broker уже подтвердил стоимость, но нормализация остановилась до сохранения ответа, metadata checkpoint не становится replay fixture. Обычный resume обязан остановиться. Оператор может отдельно разрешить отказ от этого ответа и новый оплаченный запрос: исходная строка ledger остаётся `completed` с фактическим `charged_micros`, без возврата, обнуления или подмены стоимостью резерва. Локальная immutable audit фиксирует решение; атомарное назначение нового physical call ID предотвращает незаявленную повторную отправку.

Финальный `manifest.abandoned_calls` содержит отсортированные по исходному physical ID записи:

```json
{
  "audit": {"path": "lineage/abandoned/000221.audit.json", "sha256": "..."},
  "receipt_checkpoint": {"path": "lineage/abandoned/000221.receipt.json", "sha256": "..."},
  "replacement": {"case_id": "...", "sequence": 10, "path": "llm/.../0010.json", "sha256": "...", "call_id": 222}
}
```

Числа в примере показывают формат, не утверждают состоявшийся replacement. Export audit сохраняет runtime schema `1`, `kind:confirmed_response_lost`, исходные call/model/wire SHA/reserved/charged, receipt projection, checkpoint SHA, source frontier и время решения. Из него исключаются private `key_fingerprint_sha256` и `ledger_before_sha256`; `source_audit_sha256` сохраняет hash исходной immutable runtime audit. `reason` — `normalization_failure_response_not_retained` для первоначального отказа нормализации; отдельная причина billing описана ниже, `provider_content_recovery` — `operator_attests_unavailable`, `response_retained:false`, `replay_eligible:false`, `replacement_authorized:true`. Это операторское решение о недоступности ответа, а не независимое доказательство поведения content API.

Verifier пересчитывает hashes export audit и exact metadata-only checkpoint; сверяет их ID, model, request/response SHA, timestamp и usage. Подтверждённая сумма не может быть отрицательной, превышать исходный reserve или быть ниже receipt cost с учётом округления; реальную привязку суммы к immutable ledger выполняет runtime gate. Checkpoint содержит только allowlisted metadata: никаких prompt, raw output, authorization или headers.

`source` содержит `run_id`, `case_id`, `sequence`, `inventory_sha256`, `request_sha256` и `request_body_sha256`. Последние два относятся к нормализованному donor request и donor body до routing/deduplication. Они должны совпасть с дважды воспроизведённой границей одного из сохранённых resume levels, включая ancestor. Утраченный wire SHA относится к Python-encoded фактическому запросу и отдельно связан с checkpoint; verifier не выдаёт эту локальную связь за service-side attestation.

Новый retained call обязан совпасть с case/sequence/request/body/model этой границы; его physical ID больше исходного, receipt ID отличается. Все retained physical IDs уникальны, утраченные IDs/receipts не могут появиться среди replay calls. `case.capture.replacement_for_call_ids` — точный отсортированный список исходных IDs для этого кейса. Bytes самих call records не переписываются. Текущий harness обязан включать `tools/baseline/orphan_ledger.py`; исторический minimum inventory не расширяется задним числом.

Результат verifier отдельно возвращает `abandoned_paid_calls` и `abandoned_charged_micros`. Поле `provider_cost_usd` остаётся суммой receipts сохранённых calls; оно не объявляется полным расходом task ledger. Потерянные ответы не увеличивают coverage, число replay calls или 41-case completion. Для каждой declared потери нужен реально сохранённый replacement; повторная потеря replacement не разрешается автоматически этой схемой.

SHA и согласованная локальная audit подтверждают структуру и полноту заявленного trail, но не защищают от умышленного изготовления всех согласованных файлов их владельцем. Runtime ledger, source inventory hash и source audit hash сохраняют собственную границу доверия; private ledger данные не экспортируются ради видимости независимой аттестации.


### Ответ утрачен во время ожидания billing

Причина `billing_metadata_unavailable_response_not_retained` разрешена только для исходного `uncertain/charged=NULL/generation_billing_unknown`, который позднее точно сверили с generation metadata по сохранённому receipt checkpoint. Для неё orphan audit дополнительно содержит `reconciliation_audit_sha256`, а manifest entry — `reconciliation:{path:"lineage/abandoned/<id6>.reconciliation.json",sha256}`. Старые normalization dispositions сохраняют прежнюю схему и байты.

Reconciliation export — schema2 `reconciled_checkpoint_generation_metadata`: исходная строка calls, reserve, подтверждённая стоимость, metadata и обе исходные metadata-only записи reservation/receipt с SHA. Из runtime audit исключаются только private `key_fingerprint_sha256` и `ledger_before_sha256`; исходный hash передаётся как `source_audit_sha256`. В текущий harness включается `tools/baseline/reconcile.py`. Historical harness без этого нового helper допустим; переход orphan helper разрешён только для конкретной проверенной пары SHA.

Verifier независимо сверяет call/model/request/receipt ID, обе checkpoint-привязки, исходный uncertain статус, последовательность timestamps и generation time в пределах reservation→receipt с записанным допуском: новые schema2 audit используют 5 секунд, исторические schema2 с 1 секундой проверяются именно по 1 секунде. Разрешены только целые `1` или `5`; bool и прочие значения запрещены. Сам receipt должен быть получен не позднее 5 минут от создания reservation. Ожидание billing не расширяет окно идентификации generation. Проверяются nominal/native token pairs и предел 1 миллиард для каждого счётчика (включая receipt total), BYOK, расхождение provider/receipt cost не более 1 микродоллара и charge как округление вверх `max(provider_cost,receipt_cost)+BYOK_upstream` до целого микродоллара. Неизвестные поля, приватные runtime поля, неподтверждённый cost и фиктивное утверждение remote request-hash attestation отклоняются.

Точный settlement освобождает неиспользованную часть reserve, но не восстанавливает ответ. Исходный physical call и подтверждённый cost остаются; replacement — отдельный новый оплаченный call, связанный с той же дважды воспроизведённой границей. Ledger, broker и orphan reader перепроверяют immutable settlement ref/hash; старые conservative forfeits не возвращаются. Локальные hashes доказывают согласованность сохранённых свидетельств, а не независимую аттестацию провайдера.
