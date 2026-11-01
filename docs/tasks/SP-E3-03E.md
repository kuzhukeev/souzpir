# SP-E3-03E · Summarizer: безопасная проекция

**Зачем:** закрыть Summarizer из E3-03 (03 §1.2–1.3), чтобы Orchestrator SP-E3-03B работал с ограниченной сводкой вместо произвольной истории.

**Часть чего:** третий узкий остаток SP-E3-03. После него SP-E3-03B подключает компоненты к runtime, затем исходная SP-E3-03 проходит финальную приёмку; `create_promise` остаётся SP-E3-03P.

За 1–3 часа получить внутреннюю сводку из проверенных сообщений и опубликованного snapshot с помощью fake-модели. Проекция не должна переносить чужой бренд, необъявленные data classes или исходные контакты во внешний запрос; pending/uncertain не превращать в готовую сводку. Существующие golden fixtures не менять. Persistence и доставка остаются SP-E3-03B.

Scope: `app/Seller/Engine/Summarizer/`, `app/Seller/Engine/SummaryProjection.php`, `app/Seller/Engine/SummaryPending.php`, `app/Seller/Engine/SummaryUncertain.php`, `app/Seller/Engine/SummaryUnavailable.php`, `tests/Unit/Seller/Engine/Summarizer/`, `docs/results/SP-E3-03E.md`. Приёмка: корректная сводка, чужой бренд, скрытые контакты, malformed/pending/uncertain fake-ответ. Отчёт и submit по AGENTS.md.
