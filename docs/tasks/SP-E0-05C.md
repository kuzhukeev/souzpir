# SP-E0-05C · Защита контактных данных перед ИИ

Прочитать AGENTS.md и docs/preparation/decisions.md. Выполнять только после разрешенного старта проекта и атомарного claim. Отдельный git worktree, не основной checkout.

Цель: Закрыть обходы маскирования email и телефонов: quoted local-part, domain literals, Unicode NFKC, fullwidth символы, нестандартные пробелы/тире, HTML entities, percent-encoding и вложенные JSON/tool arguments. При неоднозначном или неразбираемом представлении отклонять запрос до создания ai_calls и отправки провайдеру. Сохранить ожидаемое маскирование обычных контактов и не повреждать безобидный текст.

Зависимости (нужен done после merge): SP-E0-05.
Область записи: app/Privacy/, config/features/E0-05C.php, docs/modules/E0-05C.md, tests/Feature/Privacy/, tests/Unit/Privacy/, docs/results/SP-E0-05C.md.
Экраны: инфраструктура/контракты.
Источники: E0-05.
- E0-05: 04 §10, 08 §2.3, eyon `Services/Ai/*`, proto `Llm/OpenRouterClient`, `config/agent.php`

Приёмка:
- Проверки каждой выявленной формы: mask или безопасный отказ до driver/HTTP/DB; nested arrays и tool arguments; обычные email/plus/кириллица/телефоны/текст остаются работоспособны. Только fake transport, без внешней сети.

Дополнительно: Интегратор подключит новый изолированный сервис в общий ContactMasker/Gateway после review. До этой защиты live ИИ запрещён; регрессию проверить с E3-02.
Не изменять shared contracts/locks/чужие модели за границами scope. Если требуется расширение scope — передать интегратору точное изменение, не обходить ownership. Доменным задачам использовать loader и регистрацию, созданные фундаментом; новый общий dependency добавляет интегратор отдельно.
Проверить реальные UI → validation → Action → storage → runtime → policy → audit пути; фиктивный API не считается готовым. UI на русском, три темы, состояния loading/empty/error, 1440/1024/390 и доступность. C/D остаются честными ограничениями/«Скоро». Реальные LLM/mail/Telegram/webhook вызовы в тестах запрещены без отдельного разрешенного live этапа.
Результат: commit в ветке, docs/results/SP-E0-05C.md с выполненным scope, тестами, ограничениями и screenshots; submit переводит только в review. Done ставит интегратор после merge и проверки ancestry. Не обновлять золотые эталоны ради зеленых тестов.

## Материалы

Исходники только для чтения: C:/OSPanel/home/souzpir.local/.local/references/{eyon-pilot,laravel-prototype}.
Полный индекс изображений: planning/materials-manifest.json; точный состав по docs/sources/02b_mockup_journal.md.
