# SP-E3-08B · Завершить fake runtime следующих ходов

Размер: 1–3 часа. Функциональное продолжение, без новой возможности сверх ТЗ.

**Зачем:** закрыть E3-03, 03 §1.2–1.3: режимы open, suggest и последующие bot-ходы нужны перед fallback, инструментами и Delivery в SP-E3-09.

**Часть чего:** SP-E3-08. После частичной сдачи там работает HTTP-вход для первого bot-хода и подавление manager без AI; open/suggest/последующие bot-ходы отвечают 503. После этой карточки остаётся отдельная SP-E3-09.

Зависимости: SP-E3-08. Приоритет — сразу после зелёного CI и слияния SP-E3-08.

Работа: добавить защищённый coordinator/finalizer для open, suggest и последующих bot-ходов на fake-драйвере. Не расширять FirstTurnProtectedClaim и FirstTurnTextFinalizer за version=0 и mode=bot. Использовать существующий HTTP-вход и idempotency; suggest сохраняет подсказку без доставки. Неподдержанный реальный AI остаётся закрыт.

Scope: `app/ProtectedOperations/SellerFollowup/`, `app/Seller/Runtime/`, `routes/features/E3-08.php`, `tests/Feature/SellerRuntime/Turns/`, `docs/results/SP-E3-08B.md`. Сгенерированные типы разрешены общим правилом AGENTS.md. Чужие модули и golden fixtures не менять.

Приёмка: fake-сценарии open, suggest и последующего bot возвращают результат вместо 503; права, tenant/brand/session, ожидаемая ревизия и повтор ключа проверены; контакты не уходят во внешний AI. Локально прогнать затронутые тесты и Product. Отчёт, submit и зелёный CI до merge.