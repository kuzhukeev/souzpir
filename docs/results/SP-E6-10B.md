# SP-E6-10B — поля клиента: извлечение из опубликованной версии бренда

Проверено: карточка полностью перекрыта реализацией и тестами SP-E6-10C/D/E (слиты в main до начала этой задачи — `21700957`/`884729c8` Merge SP-E6-10E, `c01f3262` Merge SP-E6-10D): `Extractor::schema()/clientFields()`, `Policy::clientFields()/nextSlot()/computeMissing()/stageBySlots()` и `DialogReader::read($publishedFields)` уже берут поля из `policy()['client_fields']` опубликованного релиза бренда, а не из фиксированного набора. `LeadState::PRIORITY_SLOTS` остаётся статическим списком оптовых слотов прототип-бренда; обязательные доп. поля брендов учитывает отдельный проход в `Policy::computeMissing()` — это и есть «LeadState и PRIORITY_SLOTS берут поля из опубликованной версии» для MVP.

Новое: добавлен тест на реальный боевой ход (`FirstInboundRuntime` → `FirstTurnCoordinator`, не sandbox/FixtureRuntime) для непрототипного бренда с опубликованными доп. полями — ранее это проверялось только на уровне Extractor/Policy напрямую и через draft-песочницу, не через полный защищённый конвейер extractor→responder.

## Проверено
- `tests/Feature/Seller/Runtime/PublishedFieldsFirstInboundE610BTest.php` (новый, 2 теста/10 проверок): первый боевой ход для бренда «Автосервис» (не «Союз Пир») с текстовым и select-доп. полями — слоты `car_model`/`service_level` сохраняются в `conversations.state`, оптовые слоты прототипа остаются `null`; `DialogReader::read()` с переданными `publishedFields` отдаёт их с названиями из схемы. Второй тест: невалидное значение select-поля на первом ходу — extractor отбрасывает результат целиком, ход завершается `unavailable` (совпадает с поведением sandbox-пути в `PublishedFieldsSandboxReplyE610ETest`).
- Регрессия: `tests/Feature/Seller/Engine`, `tests/Feature/Seller/Runtime`, `tests/Feature/Seller/Dialogs`, `tests/Feature/Seller/Knowledge`, `tests/Feature/SellerRuntime` — 182 зелёных/9 падают с предсуществующими «An isolated test database is required» / «A dedicated, nonprivileged test database owner is required» (роль тестовой БД слота, не связано с задачей; тот же паттерн фиксировали SP-E6-10E/SP-E3-10C).
- Повторно прогнаны `ClientFieldsExtractionE610ETest`, `PublishedFieldsSandboxReplyE610ETest`, `DialogCustomFieldsE610ETest`, `PolicyTest`, `ProfileScenariosE610DTest` — 19/19 зелёных, доказывают, что приёмка карточки уже закрыта этими более ранними задачами.
- `./vendor/bin/pint --test` и `composer lint` (coverage: 724 файла, каждый покрыт один раз) — чисто.

## Остаток
- Нет (D-5.1 сама UI-форма схемы полей — экран SP-E6-10/10C, вне scope этой карточки).

## Техдолг
- Унаследован из SP-E6-10E: `Policy::nextSlot` для неоптовых брендов сначала перебирает сконфигурированный `slot_order` и только потом обязательные доп. поля — несколько лишних пропусков до `wasAsked()`.

## Принято по умолчанию
- Код не менялся: подтверждено прогоном тестов выше со ссылкой на коммиты слияния SP-E6-10C/D/E.
