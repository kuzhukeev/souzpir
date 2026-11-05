# SP-E5-04S · Защищённая подсказка менеджеру

**Зачем:** завершить экран по принятому результату.
**Часть чего:** остаток SP-E5-04R, docs/results/SP-E5-04R.md.
**Зависимости:** SP-E5-04R.

## Приёмка
- Реализовать защищённое происхождение и сохранение manager-подсказки, подключить extractor/responder/guard и gateway, проверить повторы, ошибки, права, бренд и полный fake-сценарий; без живых вызовов. Изменение общих AI-стадий предварительно согласовать с владельцем.
- Проверить права, изоляцию брендов и ошибки; незавершённое указать в «Остаток».

Scope: `app/Seller/Dialogs/`, `app/Ai/Protected/`, `app/Ai/Gateway.php`, `app/ProtectedOperations/`, `resources/js/pages/Seller/Dialogs/`, `tests/Feature/Seller/Dialogs/`, `tests/Feature/ProtectedOperations/`, `tests/Browser/E5-04S/`, `docs/results/SP-E5-04S.md`.
