# SP-E4-10L — закрытые темы: применение в диалоге

- D-4.9 «Что дальше»: в `Policy::decideBase` ветка `closed_topic` теперь читает поле темы `next`. При `return` (и когда поле отсутствует — старые fixtures/бренды) поведение не изменилось: закрыть тему и вернуться к заказу одним вопросом. При `handoff` ход сразу эскалируется через существующий `handoffGoal` (reason `closed_topic`, `message_idea` = текст поля «Как бот закрывает тему»): стадия `handoff`, флаг `escalate`, допустимые tools `escalate`/`send_price` — тот же путь, что и у остальных причин передачи менеджеру.
- Существующий `FirstTurnTextFinalizer` уже обрабатывает `goal->kind === 'handoff'` универсально (ставит `conversations.mode = manager`, `handed_off_at`), доработок в `app/ProtectedOperations/` не потребовалось.
- Экран «Запреты» (D-4.9, макет `57/b3d12815-D-4.9-topics.webp`) не менялся — поле «Что дальше» уже существовало (SP-E4-10D); проверено только подключение к обработке хода.

## Проверки
- Новый `tests/Feature/Seller/Engine/ClosedTopicNextActionE410LTest.php` — 3 теста, 11 проверок: оба варианта (`return`/`handoff`) и дефолт при отсутствии поля.
- `tests/Unit/Seller/Engine/PolicyTest.php`, `PrototypeTraceTest.php` (golden-трейсы прототипа, не менялись — зелёные), `tests/Feature/Seller/Engine/BrandKnowledgeIsolationTest.php`, `TopicReplyTest.php`, `ExtractorTest.php` — все зелёные.
- `composer lint` (Pint + coverage новых тестов в наборе) — зелёный.

## Остаток
- `tests/Feature/ProtectedOperations/FirstTurnStateTest.php` (end-to-end `closed_topic`/`handoff` через finalizer) не запускается локально: собственная проверка файла требует роль БД без `CREATEDB`, а у роли слота exec-8 этот грант есть — тест сам отказывается запускаться (`RuntimeException: An isolated test database is required`). Подтверждено стэшем, что это не связано с правкой (тот же результат без изменений). Проверка остаётся за CI.

## Техдолг
- Роль БД слота exec-8 имеет `CREATEDB` — блокирует локальный запуск тестов со строгой self-check изоляцией (как `FirstTurnStateTest`); инфраструктурный риск, не блокер.

## Принято по умолчанию
- Для handoff-варианта закрытой темы reason id = `closed_topic`, `message_idea` берётся из единственного заполняемого продавцом текста темы (поле «idea»/«Как бот закрывает тему»), плейсхолдер `{response_time}` в нём не обязателен.
