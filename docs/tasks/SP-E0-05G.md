# SP-E0-05G · Подтверждённая стоимость AI-сверки оператору

**Зачем:** решение владельца от 06.11.2026.
**Часть чего:** остаток SP-E0-05F, docs/results/SP-E0-05F.md.
**Зависимости:** SP-E0-05F.

## Приёмка
- Только оператору внутреннего экрана сверки с правом billing.manage показывать подтверждённую провайдером сумму USD; клиенту USD нигде не показывать. Неподтверждённую стоимость не выдумывать, коэффициент пересчёта в тарифные единицы не задавать.
- Проверить права, изоляцию брендов, ошибочные и пустые состояния; только fake внешние вызовы.

Scope: `app/Ai/Recovery/`, `app/Shell/Billing/`, `resources/js/pages/Shell/Billing/`, `resources/js/features/Shell/Billing/AiRecovery/`, `tests/Feature/Ai/Recovery/`, `tests/Browser/E0-05G/`, `docs/results/SP-E0-05G.md`.
