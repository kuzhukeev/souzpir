

## Остаток 07.11.2026 23:40 (после приёмки)
- При `goal->kind === 'unknown_fact'` создавать задачу менеджеру `seller_tasks` (`type='unknown_question'`, `payload.text` с вопросом, `due_at` = конец дня, по образцу `App\Seller\Engine\CreatePromise`) в `FirstTurnTextFinalizer::write()` и `FollowupFinalizer::finishProtectedApi()`; feature-тест, бренд. Файлы финализаторов добавлены в scope; зависимость от SP-E7-04M (она их сейчас правит).
- Дополнительный scope: `app/ProtectedOperations/FirstTurnTextFinalizer.php`, `app/ProtectedOperations/SellerFollowup/FollowupFinalizer.php`, `tests/Feature/ProtectedOperations/`.

## Уточнение scope 07.11.2026 23:55 (планировщик)
- Scope сужен под остаток 23:40: два финализатора и Topics; feature-тесты — в `tests/Feature/Seller/Knowledge/Topics/` (каталог `tests/Feature/ProtectedOperations/` в phpunit.xml перечислен пофайлово, новые файлы там не запустятся). `app/Seller/Runtime/`, `app/Seller/Engine/` и `tests/Feature/Seller/Runtime/` убраны — `CreatePromise` только образец, его не менять.


## Уточнение 08.11.2026 00:30 (планировщик)
- Зависимость от SP-E7-04M снята: его изменения финализаторов влиты (c1f406ac), остаток SP-E7-04M финализаторы не трогает и убран из его scope. В `payload` задачи писать и `text`, и `question` (экран D-3.3 читает оба).


## Остаток 08.11.2026 (аудит 08.11, планировщик)
- D-4.6: «Частота за 30 дней» и «Ответы „не знаю“» считаются только по первому ходу: `meta.intent` пишет только `FirstTurnTextFinalizer` (стр. 204), а `FollowupFinalizer` (стр. 83) — нет. Добавить `intent` (из `client_intent` извлечения последующего хода, если оно доступно в финализаторе) в meta ответа агента и учесть клиентское сообщение последующего хода в `TopicsController` (частота и примеры вопросов). Только добавление ключа meta: текст ответа, решения и записанный replay/golden не меняются. Feature-тест: последующий ход попадает в частоту и долю «не знаю» своего бренда, чужой бренд не виден.
