# SP-E3-07D · Полный replay runtime

**Зачем:** завершить соответствующий сценарий и экран по результату SP-E3-07C.
**Часть чего:** остаток SP-E3-07C, docs/results/SP-E3-07C.md.
**Зависимости:** SP-E3-07C.

## Приёмка
- Подключить адаптер к новому runtime и сопоставить запросы; выполнить все 41 записанный сценарий без сети и изменения golden fixtures; проверить follow-up, ошибки и изоляцию брендов, включить полный replay в CI и записать подтверждённые расхождения в docs/sources/09. Общие AI-стадии менять только добавлениями, с fake AI и эксклюзивным ресурсом.
- Сохранить существующее поведение, проверить права, ошибки и изоляцию брендов; использовать fake runtime.

Scope: `app/Seller/Runtime/`, `app/Seller/Followups/`, `app/Ai/`, `tests/Feature/Equivalence/`, `docs/validation/equivalence.md`, `docs/sources/09_questions_assumptions_decisions.md`, `.github/workflows/ci.yml`, `docs/results/SP-E3-07D.md`.
