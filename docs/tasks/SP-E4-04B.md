# SP-E4-04B · Применить ответы и действия темы в движке

**Зачем:** экраны D-4.6–D-4.7, макеты 54–55: сохранённые `default_answer` и `after_action` должны менять следующий ход диалога.

**Часть чего:** SP-E4-04 дала редактор и хранение полей; здесь остаётся их применение в Policy/Responder и проверка неизменности golden-промпта при пустых полях. После этой карточки остатка E4-04 нет.

Размер 1–3 часа. Зависимости: SP-E4-04, SP-E3-08. Scope: `app/Seller/Engine/Policy.php`, `app/Seller/Engine/Responder/Responder.php`, `tests/Feature/Seller/Engine/`, `tests/Unit/Seller/Engine/`, `docs/results/SP-E4-04B.md`. Не менять golden fixtures. Только fake LLM; проверить права бренда, обычное и пустое значение, аудит существующего пути.
