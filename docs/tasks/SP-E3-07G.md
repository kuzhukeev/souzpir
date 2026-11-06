# SP-E3-07G · Рабочий runtime replay

**Зачем:** закрыть конкретный остаток полного replay SP-E3-07F и проверку эквивалентности прототипу.
**Часть чего:** SP-E3-07F, docs/results/SP-E3-07F.md.
**Зависимости:** SP-E3-07F в done.

## Приёмка
- Реализовать воспроизведение 39 диалоговых и 2 таймерных сценариев: сопоставить этапы, историю, запросы и raw tool calls с записанными ответами, включая старт без входящего сообщения и последующие ходы; устранить отказ manual.no_repeat_filled_slot, сравнить итоговые состояния и ошибки. Включить реальный replay в CI; эталонные golden fixtures не менять.
- Проверить права и изоляцию брендов; только записанный fake, без живых вызовов. Описать реальные расхождения и недоступные сценарии.
- Нужен работающий прогон, а не ещё один диагностический отчёт о несовпадении хешей.

Scope: `app/Ai/Gateway.php`, `app/Seller/Runtime/`, `app/ProtectedOperations/SellerFollowup/FollowupCoordinator.php`, `app/ProtectedOperations/SellerFollowup/FollowupFinalizer.php`, `app/Seller/Followups/PrepareFollowups.php`, `app/Seller/Followups/FollowupSender.php`, `tests/Feature/Equivalence/Support/RecordedGateway.php`, `tests/Equivalence/`, `docs/validation/`, `docs/results/SP-E3-07G.md`.
