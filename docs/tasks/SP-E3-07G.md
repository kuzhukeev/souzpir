# SP-E3-07G · Рабочий runtime replay

**Зачем:** закрыть конкретный остаток полного replay SP-E3-07F и проверку эквивалентности прототипу.
**Часть чего:** SP-E3-07F, docs/results/SP-E3-07F.md.
**Зависимости:** SP-E3-07F в done.

## Приёмка
- Реализовать воспроизведение 39 диалоговых и 2 таймерных сценариев: сопоставить этапы, историю, запросы и raw tool calls с записанными ответами, включая старт без входящего сообщения и последующие ходы; устранить отказ manual.no_repeat_filled_slot, сравнить итоговые состояния и ошибки. Включить реальный replay в CI; эталонные golden fixtures не менять.
- Проверить права и изоляцию брендов; только записанный fake, без живых вызовов. Описать реальные расхождения и недоступные сценарии.
- Нужен работающий прогон, а не ещё один диагностический отчёт о несовпадении хешей.

Scope: `app/Ai/Gateway.php`, `app/Seller/Runtime/`, `app/ProtectedOperations/SellerFollowup/FollowupCoordinator.php`, `app/ProtectedOperations/SellerFollowup/FollowupFinalizer.php`, `app/Seller/Followups/PrepareFollowups.php`, `app/Seller/Followups/FollowupSender.php`, `tests/Feature/Equivalence/Support/RecordedGateway.php`, `tests/Equivalence/`, `docs/validation/`, `docs/results/SP-E3-07G.md`.

## Уточнение scope 07.11.2026 22:50
- `app/ProtectedOperations/SellerFollowup/FollowupFinalizer.php` занят SP-E7-04M и исключён из scope; если правка финализатора нужна для replay — пункт в «Нужно от интегратора».

## Сужение scope 07.11.2026 23:30 (планировщик)
- `app/Seller/Runtime/` исключён из scope: каталог занят 6 активными задачами, replay идёт через защищённые стадии и follow-up; если правка runtime-файла нужна — пункт в «Нужно от интегратора».


## Остаток 08.11.2026 (планировщик, запуск 20261107-225307)
- Паритет с прототипом (golden fixtures не менять): 29 кейсов `persona.*` через `ApiOpeningRuntime`, `manual.force_close_14`, followup через 40 ч (`FollowupPolicy`).
- responder-стадия LeadState (9 кейсов) требует `app/Ai/Protected/SellerResponderStages.php` и `app/ProtectedOperations/FirstTurnState.php`, которые сейчас у SP-E4-01X, — в эту часть не входит; перечислить в «Остаток» отчёта.
- Нужен ещё файл вне scope — «Нужно от интегратора», остальное делать.

Scope: `app/Seller/Runtime/ApiOpeningRuntime.php`, `app/Seller/Followups/FollowupPolicy.php`, `app/Seller/Engine/Policy.php`, `tests/Feature/Equivalence/Support/RecordedGateway.php`, `tests/Equivalence/`, `docs/validation/equivalence.md`, `docs/results/SP-E3-07G.md`.
