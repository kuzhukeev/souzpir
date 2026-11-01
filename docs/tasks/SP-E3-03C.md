# SP-E3-03C · Responder: проверенный запрос и сообщение

**Зачем:** закрыть часть E3-03 (03 §1.2–1.3, прототип Responder), без которой Orchestrator SP-E3-03B не может собрать ответ из опубликованного prompt и snapshot.

**Часть чего:** первый из трёх узких остатков SP-E3-03. После него остаются `llmCheck` Guard (SP-E3-03D), Summarizer (SP-E3-03E), Orchestrator (SP-E3-03B) и финальная приёмка SP-E3-03.

За 1–3 часа собрать внутренний Responder из существующих `ResponderRequest`, `ResponderMessages` и `ResponderResult`: принимать только опубликованный tenant-bound snapshot/prompt, передавать явные data classes и корректно сохранять различие completed / in_progress / uncertain. Вызов ИИ — через fake-интерфейс; persistence, tools и доставка здесь не подключаются. Существующие golden fixtures не менять.

Scope: `app/Seller/Engine/Responder/`, три названных файла `Responder*.php`, `tests/Unit/Seller/Engine/Responder/`, `docs/results/SP-E3-03C.md`. Приёмка: верный prompt/snapshot, чужой brand или неопубликованный релиз, pending/uncertain и неверный ответ; unit-тесты без сети и БД. Отчёт и submit по AGENTS.md.
