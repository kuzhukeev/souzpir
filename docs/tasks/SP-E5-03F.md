# SP-E5-03F · Защищённый первый ход API

**Зачем:** конкретный незакрытый пользовательский сценарий D-9.9 и защищённый fake runtime.
**Часть чего:** остаток SP-E5-03E, docs/results/SP-E5-03E.md.
**Зависимости:** SP-E5-03E; общие AI-стадии через shared-ai-stages.

## Приёмка
- D-9.9: action=open и сохраняемый выбор «Написать первым»: защищённый исходящий ход без клиентского сообщения через Policy, Responder и Guard, хранение, повтор, изоляция и UI.
- Заменить фиксированные fake-ответ и подсказку FollowupFinalizer защищёнными extractor/responder/guard и Gateway; проверить полный fake-сценарий, ошибки, повторы, права и бренд.
- Реализовать двухэтапный путь Gateway вне транзакции ResolveTokenTenant с повторной проверкой полномочий и сквозным тестом; существующие защищённые стадии требуют входящего сообщения и сессии.
- Проверить экран, маршрут, обработчик, хранение и видимый результат; ошибки, права и бренд. Только fake, без живых или платных вызовов.

Scope: `app/Ai/Gateway.php`, `app/Ai/Protected/`, `app/ProtectedOperations/SellerFollowup/FollowupCoordinator.php`, `app/ProtectedOperations/SellerFollowup/FollowupFinalizer.php`, `app/Seller/Runtime/ApiFollowupRuntime.php`, `app/Seller/Runtime/TokenInboundRuntime.php`, `app/Seller/Channels/Api/LeadIngressController.php`, `app/Tenancy/TokenApi/TokenTenantAuthority.php`, `app/Tenancy/TokenApi/ResolveTokenTenant.php`, `routes/features/E5-03.php`, `tests/Feature/Ai/`, `tests/Feature/Seller/Channels/Api/`, `tests/Feature/SellerRuntime/Turns/FollowupHttpTest.php`, `tests/Feature/SellerRuntime/Turns/ProtectedFollowupFixtures.php`, `tests/Feature/Seller/Evaluation/ProtectedSimulationRuntimeTest.php`, `tests/Feature/Seller/Evaluation/SimulationBridgeE306DTest.php`, `docs/results/SP-E5-03F.md`.

## Остаток после частичной приёмки
- Simulation job переведён на защищённые extractor/responder/guard и fake Gateway; см. отчёт SP-E5-03F.
- Реализовать защищённые инструменты, повторную генерацию и модельный guard последующих ходов (сейчас безопасный 503).
- Повтор после подтверждённой ошибки fake Gateway реализован; успешный повтор идемпотентен, неопределённый результат повторно не отправляется.
- Проверить входы старого транзакционного `TokenInboundRuntime` и заменить фиксированный fallback там, где он участвует в согласованных сценариях.


## Следующий этап
Повтор после подтверждённой ошибки fake Gateway принят. Инструменты, регенерация, модельный guard и применяемый legacy fallback остаются в этой же карточке; занятый Seller Engine не изменять.

Защищённые инструменты, повторная генерация и модельный Guard выполняются через текущие API и fake Gateway в разрешённых файлах; если требуется принадлежащий SP-E6-10E Seller Engine, зафиксировать конкретный неработающий пункт в этой же карточке и продолжать свободное. Реальные и платные вызовы не включать.
