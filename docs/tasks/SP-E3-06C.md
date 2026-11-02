# SP-E3-06C · Judge и ежедневный Supervisor

**Размер:** 1–3 часа. **Зачем:** завершить `AgentJudge`/`AgentSupervise` из SP-E3-06 и `docs/sources/03_source_analysis_and_transfer_map.md`.

**Часть чего:** финальный остаток SP-E3-06 после SP-E3-06B; дальнейших продолжений E3-06 не создавать.

**Зависимости:** SP-E3-06B в done.

**Scope:** `app/Seller/Evaluation/`, `tests/Feature/Seller/Evaluation/`, `tests/Unit/Seller/Evaluation/`, `routes/console.php`, `docs/results/SP-E3-06C.md`.

Judge оценивает завершённый run через fake-драйвер и сохраняет объяснимый результат по бренду; Supervisor ежедневно агрегирует проблемы без повторной обработки. Тесты — оценка, идемпотентный повтор, расписание и изоляция бренда. Живые AI-вызовы запрещены.
