# SP-E3-06D · Адаптер симуляции к защищённому Seller runtime

Размер: 1–3 часа. Третье и последнее продолжение E3-06 по явному решению владельца от 2026-11-02; функциональный пункт ТЗ, не новая возможность сверх ТЗ.

**Зачем:** `AgentSimulate` из E3-06 (`docs/sources/03_source_analysis_and_transfer_map.md`) должен запускать настоящие защищённые fake-ходы. SP-E3-06B добавила интерфейс и трассу, но `SimulateFakeJob` всё ещё использует прежнюю FakeSimulation.

**Часть чего:** остаток SP-E3-06B. SP-E3-06C отдельно делает Judge и Supervisor; после D функционального остатка E3-06 нет. Дальше E3-06 не дробить.

Зависимости: SP-E3-06B, SP-E3-06C и SP-E3-08B в done. Не пересекаться с активной веткой C в одном модуле.

Работа: реализовать `SimulationRuntime` через защищённый fake Seller runtime для `open/turn` одного бренда и подключить его к `SimulateFakeJob`/`RuntimeSimulation`. Если текущий HTTP-вход требует браузерную сессию, сделать внутренний entrypoint для доверенного job-контекста в разрешённом ProtectedOperations с теми же tenant/brand и idempotency-проверками. Не дублировать отдельный движок ходов и не обходить защиту прямыми SQL-записями. Живые, платные AI-вызовы и внешние доставки не включать.

Scope: `app/Seller/Evaluation/`, `app/ProtectedOperations/SellerSimulation/`, `app/ProtectedOperations/SellerFollowup/FollowupCoordinator.php`, `tests/Feature/Seller/Evaluation/`, `tests/Unit/Seller/Evaluation/`, `docs/results/SP-E3-06D.md`. Чужие модули и golden fixtures не менять.

Приёмка: job с одной из 29 персон сохраняет трассу open/turn через защищённый fake runtime; чужой бренд, повтор ключа и ошибка хода проверены; внешний AI не вызывается. Затронутые тесты и Product проходят локально, CI ветки зелёный.