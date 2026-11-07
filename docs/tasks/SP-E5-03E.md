# SP-E5-03E · Первый защищённый API-ход и журнал

**Зачем:** конкретные незакрытые пункты D-9.9 и защищённого API-хода.
**Часть чего:** остаток SP-E5-03D, docs/results/SP-E5-03D.md.
**Зависимости:** SP-E5-03D; общие AI-стадии через shared-ai-stages.

## Приёмка
- D-9.9: реализовать action=open и сохраняемый выбор «Написать первым»: защищённый первый исходящий ход без клиентского сообщения через Policy, Responder и Guard, с хранением, повтором и изоляцией.
- Заменить фиксированные fake-подсказку и ответ FollowupFinalizer защищёнными extractor/responder/guard и Gateway; проверить полный fake-сценарий, ошибки, повторы, права и бренд.
- Сохранить и отобразить источник сайт или CRM по согласованному контракту в журнале D-9.9; проверить экран после подключения выбора.
- Согласовать двухэтапный путь для Gateway вне транзакции ResolveTokenTenant; проверить сквозной результат и обработку ошибок без живых или платных вызовов.
- Проверить UI, маршрут, обработчик, хранение и видимый результат, изоляцию брендов, права, 1–2 ошибки и fake; прежние неизменённые проверки не повторять ради карточки.

Scope: `app/Seller/Channels/Api/`, `app/Seller/Runtime/`, `app/ProtectedOperations/`, `app/Ai/Protected/`, `app/Ai/Gateway.php`, `app/Tenancy/TokenApi/`, `resources/js/pages/Seller/Channels/Api/`, `routes/features/E5-03.php`, `tests/Feature/Seller/Channels/Api/`, `tests/Feature/ProtectedOperations/`, `tests/Feature/SellerRuntime/Turns/`, `tests/Feature/Ai/`, `tests/Browser/E5-03E/`, `docs/results/SP-E5-03E.md`.
