# SP-E3-03B · Orchestrator, turn/open и режимы доставки

Прочитать AGENTS.md и docs/preparation/decisions.md. Выполнять только после разрешенного старта проекта и атомарного claim. Отдельный git worktree, не основной checkout.

Цель: Интегрировать перенесённые части движка с persistence и AiGateway, runtime per brand, meta, tool-calls, retry/fallback. bot доставляет через Delivery contract; suggest сохраняет подсказку без отправки; manager молчит. Доставка каналов остается отдельным владельцам.

Зависимости (нужен done после merge): SP-E0-04, SP-E3-03, SP-E3-04.
Область записи: app/Seller/Engine/, app/Seller/Runtime/, tests/Feature/SellerRuntime/, docs/results/SP-E3-03B.md.
Экраны: инфраструктура/контракты.
Источники: E3-03.
- E3-03: 03 §1.2–1.3, proto `Responder`, `Guard`, `ToolRunner`, `Orchestrator`

Приёмка:
- TurnTest/open/regeneration/fallback, tenant isolation, double-submit/idempotency и defaults-equivalence проходят.
- Чужие или подменённые state/history/input и несовпадающая claimed message отвергаются до ИИ и сохранения; проверены гонки и отсутствие передачи контактов.

Дополнительно:  Обязательная граница: state/history/clientMessage и claimed input загружать из persistence по tenant + conversation + inputMessage, сверять с захваченным turn; не доверять произвольным значениям caller. Использовать проверенные опубликованные release/prompt, обязательные dataClasses и сохранять исходные контакты локально без отправки наружу. Pending/uncertain не финализировать как fallback и не запускать платный запрос повторно. Методы с сырой историей, TurnCommand и сообщениями должны скрывать их из трасс исключений (SensitiveParameter/generic errors); проверить рекурсивно getTrace и строку исключения при zend.exception_ignore_args=0.
Не изменять shared contracts/locks/чужие модели за границами scope. Если требуется расширение scope — передать интегратору точное изменение, не обходить ownership. Доменным задачам использовать loader и регистрацию, созданные фундаментом; новый общий dependency добавляет интегратор отдельно.
Проверить реальные UI → validation → Action → storage → runtime → policy → audit пути; фиктивный API не считается готовым. UI на русском, три темы, состояния loading/empty/error, 1440/1024/390 и доступность. C/D остаются честными ограничениями/«Скоро». Реальные LLM/mail/Telegram/webhook вызовы в тестах запрещены без отдельного разрешенного live этапа.
Результат: commit в ветке, docs/results/SP-E3-03B.md с выполненным scope, тестами, ограничениями и screenshots; submit переводит только в review. Done ставит интегратор после merge и проверки ancestry. Не обновлять золотые эталоны ради зеленых тестов.

## Материалы

Исходники только для чтения: C:/OSPanel/home/souzpir.local/.local/references/{eyon-pilot,laravel-prototype}.
Полный индекс изображений: planning/materials-manifest.json; точный состав по docs/sources/02b_mockup_journal.md.
