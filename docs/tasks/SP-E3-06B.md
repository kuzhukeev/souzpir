# SP-E3-06B · Симуляция через полный runtime

**Размер:** 1–3 часа. **Зачем:** завершить `AgentSimulate` из SP-E3-06 и `docs/sources/03_source_analysis_and_transfer_map.md`.

**Часть чего:** остаток SP-E3-06 после fake-фикстур и сохранения оценок. После B останутся Judge и ежедневный Supervisor в SP-E3-06C.

**Зависимости:** SP-E3-06 и SP-E3-08 в done. Использовать API runtime, не менять `app/Seller/Runtime/`.

**Scope:** `app/Seller/Evaluation/`, `tests/Feature/Seller/Evaluation/`, `tests/Unit/Seller/Evaluation/`, `docs/results/SP-E3-06B.md`.

Симуляция запускает проверенные fake-ходы через `open/turn`, генерирует следующие ходы клиента из 29 персон и сохраняет трассу без живых AI-вызовов. Ошибка одного хода завершает run с явным статусом. Тесты — сценарий, бренд и ошибка.
