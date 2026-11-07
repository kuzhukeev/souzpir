

## Остаток 07.11.2026 23:40 (после приёмки)
- При `goal->kind === 'unknown_fact'` создавать задачу менеджеру `seller_tasks` (`type='unknown_question'`, `payload.text` с вопросом, `due_at` = конец дня, по образцу `App\Seller\Engine\CreatePromise`) в `FirstTurnTextFinalizer::write()` и `FollowupFinalizer::finishProtectedApi()`; feature-тест, бренд. Файлы финализаторов добавлены в scope; зависимость от SP-E7-04M (она их сейчас правит).
- Дополнительный scope: `app/ProtectedOperations/FirstTurnTextFinalizer.php`, `app/ProtectedOperations/SellerFollowup/FollowupFinalizer.php`, `tests/Feature/ProtectedOperations/`.

## Уточнение scope 07.11.2026 23:55 (планировщик)
- Scope сужен под остаток 23:40: два финализатора и Topics; feature-тесты — в `tests/Feature/Seller/Knowledge/Topics/` (каталог `tests/Feature/ProtectedOperations/` в phpunit.xml перечислен пофайлово, новые файлы там не запустятся). `app/Seller/Runtime/`, `app/Seller/Engine/` и `tests/Feature/Seller/Runtime/` убраны — `CreatePromise` только образец, его не менять.


## Уточнение 08.11.2026 00:30 (планировщик)
- Зависимость от SP-E7-04M снята: его изменения финализаторов влиты (c1f406ac), остаток SP-E7-04M финализаторы не трогает и убран из его scope. В `payload` задачи писать и `text`, и `question` (экран D-3.3 читает оба).
