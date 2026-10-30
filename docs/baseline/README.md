# Эталон прототипа продавца: offline evidence

SP-T-01, 31.10.2026. Донор — архив Laravel-прототипа «Союз Пир», не M3 из EYON. Исходные PHP, YAML, prompts и тестовые ожидания не изменены. Runtime-копия и зависимости находятся в игнорируемом `.local/prototype` этого worktree.

## Что зафиксировано

`tests/fixtures/prototype/manifest.json` содержит ровно **41 case ID**: 29 персон из `tools/personas.yaml` донора и 12 ручных сценариев из §2.1 плана тестирования.

| Вид данных | Количество | Происхождение |
|---|---:|---|
| Реальные диалоги LLM (`recorded`) | 0 | Реальные записи не предоставлены, платный прогон не выполнялся |
| Персоны без записи (`missing`) | 29 | ID, названия, max_turns и ожидания перенесены из исходного YAML; диалоги не выдуманы |
| Ручные traces (`synthetic`) | 12 | 10 запусков исходной Policy с явно заданным extraction; 2 сценария исходного scheduler на SQLite `:memory:` |
| Rendered prompt snapshots | 20 | 4 реальных Blade-шаблона × 5 фиксированных состояний |
| Synthetic Orchestrator replay | 1 сценарий / 3 LLM-вызова | Исходный Orchestrator, ответы исходного FakeLlmClient, начало диалога и заполнение города/цели |

Synthetic traces проверяют детерминированное поведение и инфраструктуру replay. Они не доказывают качество извлечения реальной моделью, естественность ответов или 100% эквивалентность перенесённого движка. Полный записанный baseline остаётся отдельным операторским этапом SP-T-03.

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

Состояния: `opening`, `newbie_qualified`, `experienced_price`, `opt_out`, `deferred_escalation`. Для каждого отрендерены `extractor`, `responder`, `guard`, `summary` из исходных Blade views; responder сформирован реальным `Responder::systemPrompt`. SHA-256 содержится в manifest.

Перед сохранением нормализуются переводы строк и редактируются секреты/почтовые адреса. Поэтому snapshots — проверяемое безопасное представление реально отрендеренного текста, а не обещание исходных байтов с контактами. После переноса применяется та же нормализация. Пять входных состояний сохранены отдельно; часы заморожены. Порядок JSON-ключей канонизируется, порядок списков сохраняется; пустой объект отличается от пустого списка. Из request options удаляются только runtime `timestamp`, `request_id`, `trace_id`, `lead_id`, `message_id`; даты внутри сообщений и слотов сохраняют смысл. Из результатов диалогов исключены только wall-clock latency и DB message ID.

`source-fingerprints.json` фиксирует SHA исходного app, config, knowledge, prompt views, tests, tools и composer.lock. Проверка against оригинального read-only донора подтверждает, что результаты получены на неизменённой логике.

## Recording/replay harness

`tools/baseline/FixtureClient.php` реализует интерфейс донора `LlmClient`:

- `synthetic` принимает только собственный FakeLlmClient; у него нет live delegate. Запись помечается synthetic и редактируется до записи на диск.
- `replay` читает последовательные fixtures, сверяет SHA и нормализованный запрос; отсутствие записи, несовпадение или неиспользованный остаток приводит к ошибке. Сетевого fallback нет.
- Повторная запись в каталог с evidence запрещена. Команда `build.php` также отказывается перезаписывать output. Новые кандидаты генерируются в новый каталог, сравниваются и рассматриваются отдельно.
- SP-T-03 должен добавить явно разрешённый live recorder и доказуемое происхождение recorded fixtures. Текущий инструмент не запускает реальную модель даже по параметру `live`.

## Воспроизведение

Интерпретаторы текущего компьютера:

```powershell
$php = 'C:\OSPanel\modules\PHP-8.4\php.exe'
$python = 'C:\Users\eralash\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe'
$offline = @('-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,mail')
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

`verify.py --require-recorded` сейчас обязан завершиться ненулевым кодом: real recorded 0/41. Обычная проверка подтверждает корректность доступного offline-пакета и явно выводит missing/synthetic, а не скрывает отсутствующий live baseline.

## Фактические проверки

- PHP 8.4.19, Composer 2.9.5, donor Laravel 13.38.0 и PHPUnit 12.5.40 из lockfile.
- 31 исходный тест: **31 passed, 71 assertions**; ожидания не менялись.
- Первый запуск выявил отсутствующий compiled-view cache path; после создания только runtime-каталогов весь исходный набор прошёл. Изменений кода донора не потребовалось.
- Harness selftest: 14 checks (нормализация, различение object/list, редактирование секретов, replay, mismatch/missing/exhaustion, отклонение неизвестного provenance, запрет перезаписи/live).
- Изолированная генерация 41 manifest entries, 20 prompts и replay; повторная генерация совпала побайтово после нормализации.
- Реальных LLM/mail/Telegram/webhook отправок: 0. UI не затрагивается, screenshots к этой задаче неприменимы.
