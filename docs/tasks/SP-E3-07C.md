# SP-E3-07C · Полный replay записанных сценариев runtime

**Зачем:** завершить финальную эквивалентность SP-E3-07B по 41 сценарию прототипа; владелец требует функционального завершения до рефакторинга.
**Часть чего:** остаток SP-E3-07B: неполный replay и неподключённый fake gateway.
**Зависимости:** SP-E3-07B (дождаться merge).

Подключить тестовый fake gateway и адаптер записанных вызовов к новому runtime, выполнить все 41 случая без живых вызовов и без изменения golden fixtures; подтвердить поведение и записать только доказанные расхождения в docs/sources/09. Проверить исходящие follow-up, ошибки и изоляцию бренда. Зелёный CI должен запускать полный replay; непроверенные случаи не считать совпавшими.

Scope: `app/Seller/Runtime/`, `app/Seller/Followups/`, `app/Ai/`, `tests/Feature/Equivalence/`, `docs/validation/equivalence.md`, `docs/sources/09_questions_assumptions_decisions.md`, `.github/workflows/ci.yml`, `docs/results/SP-E3-07C.md`.

Отчёт: docs/results/SP-E3-07C.md; проверки и остаток по пунктам, если есть.
