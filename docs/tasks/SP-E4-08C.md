# SP-E4-08C · Образец речи в промпте песочницы

**Зачем:** D-4.8 и приёмка E4-08: добавленный образец должен попадать в промпт песочницы при `Knowledge::fewshot(tags, clientType, 6, 0.7)` без изменения эталона донора.

**Часть чего:** SP-E4-08 дала редактор, SP-E4-08B добавляет плохие образцы и реплики из диалога. Здесь остаётся интеграция с готовым runtime и доказательство выбора образца; после C остатка E4-08 нет.

Размер 1–3 часа. Зависимости: SP-E4-08B, SP-E3-07B. Scope: `app/Seller/Knowledge/Fewshot/`, `app/Seller/Runtime/`, `resources/js/pages/Seller/Knowledge/Fewshot/`, `tests/Feature/Seller/Knowledge/Fewshot/`, `tests/Feature/SellerRuntime/`, `docs/results/SP-E4-08C.md`. Использовать только fake LLM, не менять golden fixtures. Проверить положительный/отрицательный образец, порог 0,7, лимит 6, изоляцию бренда и видимый результат песочницы.
