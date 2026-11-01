# SP-E3-03D · Guard: llmCheck после правил

**Зачем:** закрыть `llmCheck` из E3-03 (03 §1.2–1.3, прототип Guard), необходимый защищённому Orchestrator SP-E3-03B.

**Часть чего:** второй узкий остаток SP-E3-03. SP-E3-03C даёт проверенный контекст запроса; SP-E3-03E остаётся для сводки, SP-E3-03B — для runtime.

За 1–3 часа добавить внутренний `llmCheck` поверх существующего `DeterministicGuard`: использовать только проверенный запрос Responder и fake-модель, строго проверить ответ модели, при ошибке направить на handoff/uncertain без выдачи разрешения. Существующие 22 детерминированных правила и golden fixtures не менять. Production Gateway, persistence и доставка остаются SP-E3-03B.

Scope: `app/Seller/Engine/Guard/`, `app/Seller/Engine/DeterministicGuard.php`, `app/Seller/Engine/GuardRequest.php`, `app/Seller/Engine/GuardDecision.php`, `app/Seller/Engine/GuardModelResult.php`, `tests/Unit/Seller/Engine/Guard/`, `docs/results/SP-E3-03D.md`. Приёмка: разрешение, отказ, malformed/pending/uncertain fake-ответ и отсутствие внешних вызовов. Отчёт и submit по AGENTS.md.
