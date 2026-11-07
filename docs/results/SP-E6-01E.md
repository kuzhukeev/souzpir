Модель: Sonnet 5

# SP-E6-01E — подключить служебные фразы к ходу продавца

Сделано: `ServicePhraseSelector::customForEvent()` (только явно сохранённая фраза, без дефолта) подключён к `FirstTurnTextFinalizer` и `FollowupFinalizer` для событий `unknown_fact` (ключ `no_answer`) и `handoff` — если продавец сохранил фразу, финальный текст первого/последующего ответа заменяется на неё независимо от текста fake-AI; если фраза не сохранена — поведение не меняется (совместимо со старыми fixtures).

Проверки (fake-прогон настоящего хода через `/seller/turns/first`, оба события): `tests/Feature/Seller/Runtime/ServicePhraseOverrideE601ETest.php` — 2 теста, 12 проверок (связка extractor→responder→guard→finalizer→БД, изоляция в своём org/brand). Регрессия существующих fixtures: `ServicePhraseSelectorTest.php`, `QuotaRuntimeE113Test.php`, `ReactionRuntimeE603VTest.php`, `FirstSellerTurnHttpTest.php` — зелёные без изменений. `phpunit.xml` не трогал (файл вне scope) — тест лежит в уже покрытой wildcard-директории `tests/Feature/Seller`. `check lint` — Pint ok, composer validate ok, покрытие suites ok.

`tests/Feature/ProtectedOperations/FirstTurnStateTest.php` (golden, suite CoreTurns) не запускается в слоте exec-9: его `createApplication()` требует роль БД без CREATEDB (у роли слота `rolcreatedb=true`) — ограничение окружения, не связано с диффом; изменённые в нём строки не трогались. Полный прогон — на CI.

## Остаток
- `error`, `out_of_hours`, `opt_out`, `reminder`, `goodbye` — не подключены: `opt_out` на первом ходе осознанно отклоняется протектед-финализатором (`ReactionRuntimeE603VTest`, SP-E6-03V) — не трогал; `out_of_hours` уже показывается только в настройках расписания (`ScheduleController`, вне scope) и по факту передаётся фразой `handoff` (`BrandRuntimeSchedule::fallbackPhrase`, подключено ранее); `reminder`/`goodbye` формируются в `app/Seller/Followups/` — вне scope карточки; `error` не имеет в scope детерминированной точки формирования клиентского текста (сбои сейчас завершаются `unavailable`/handoff менеджеру без текста). Нужна отдельная карточка с явным scope на `app/Seller/Followups/` и/или `app/Seller/Engine/`.

## Техдолг
- Нет новых рисков сверх описанных выше ограничений scope.

## Принято по умолчанию
- Переопределение текста применяется только при явно сохранённой непустой фразе (без дефолтов редактора) — так старые fixtures с AI-текстом остаются рабочими, а дефолтное поведение не трогается.
