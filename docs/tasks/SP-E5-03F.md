# SP-E5-03F · Защищённый первый ход API

**Зачем:** конкретный незакрытый пользовательский сценарий D-9.9 и защищённый fake runtime.
**Часть чего:** остаток SP-E5-03E, docs/results/SP-E5-03E.md.
**Зависимости:** SP-E5-03E; общие AI-стадии через shared-ai-stages.

## Приёмка
- D-9.9: action=open и сохраняемый выбор «Написать первым»: защищённый исходящий ход без клиентского сообщения через Policy, Responder и Guard, хранение, повтор, изоляция и UI.
- Заменить фиксированные fake-ответ и подсказку FollowupFinalizer защищёнными extractor/responder/guard и Gateway; проверить полный fake-сценарий, ошибки, повторы, права и бренд.
- Реализовать двухэтапный путь Gateway вне транзакции ResolveTokenTenant с повторной проверкой полномочий и сквозным тестом; существующие защищённые стадии требуют входящего сообщения и сессии.
- Проверить экран, маршрут, обработчик, хранение и видимый результат; ошибки, права и бренд. Только fake, без живых или платных вызовов.

Scope: `app/Seller/Channels/Api/`, `app/Seller/Runtime/`, `app/ProtectedOperations/`, `app/Ai/Protected/`, `app/Ai/Gateway.php`, `app/Tenancy/TokenApi/`, `resources/js/pages/Seller/Channels/Api/`, `routes/features/E5-03.php`, `tests/Feature/Seller/Channels/Api/`, `tests/Feature/ProtectedOperations/`, `tests/Feature/SellerRuntime/Turns/`, `tests/Feature/Ai/`, `tests/Browser/E5-03F/`, `docs/results/SP-E5-03F.md`.

## Остаток после частичной приёмки
- Заменить фиксированный ответ simulation job `FollowupCoordinator::runJob` защищёнными extractor/responder/guard и Gateway; адаптировать связанные тесты `tests/Feature/Seller/Evaluation/` после точечного расширения scope интегратором.
- Реализовать защищённые инструменты, повторную генерацию и модельный guard последующих ходов (сейчас безопасный 503).
- Добавить явный повтор после ошибки fake Gateway; сохранить идемпотентность завершённого запроса.
