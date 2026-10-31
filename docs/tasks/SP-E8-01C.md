# SP-E8-01C · Разбор ответов и подтверждение упоминаний

Прочитать AGENTS.md и docs/preparation/decisions.md. Выполнять только после разрешенного старта проекта и атомарного claim. Отдельный git worktree, не основной checkout.

Цель: Перенести смысл AnswerParser, MentionConfirmer, EntityNormalizer и VisibilityProfile из исходного Vision: schema/prompt, позиции, цитаты, алиасы и неоднозначные совпадения. Работа по замороженным доверенным DTO, через AiGateway; сохранять hash версии prompt/schema, недоказанные факты обозначать unknown. Только fake LLM.

Зависимости (нужен done после merge): SP-E0-05, SP-E0-05C.
Область записи: app/Vision/Evidence/, config/features/E8-01C.php, docs/modules/E8-01C.md, resources/prompts/Vision/Evidence/, tests/Feature/Vision/Evidence/, tests/Unit/Vision/Evidence/, docs/results/SP-E8-01C.md.
Экраны: инфраструктура/контракты.
Источники: E8-01.
- E8-01: 03 §2.1; eyon `Services/Visibility/*`, `Audit/*`; R-V-05

Приёмка:
- Регрессии на donor fixtures: цитаты, порядок, алиасы, похожие названия, неоднозначность и отказ без подтверждения; нет внешних сетевых запросов.

Дополнительно: Независимо от реализации Core; контракт DTO согласовать с SP-E8-01. SP-E8-01 не закрывать до сквозной интеграции.
Не изменять shared contracts/locks/чужие модели за границами scope. Если требуется расширение scope — передать интегратору точное изменение, не обходить ownership. Доменным задачам использовать loader и регистрацию, созданные фундаментом; новый общий dependency добавляет интегратор отдельно.
Проверить реальные UI → validation → Action → storage → runtime → policy → audit пути; фиктивный API не считается готовым. UI на русском, три темы, состояния loading/empty/error, 1440/1024/390 и доступность. C/D остаются честными ограничениями/«Скоро». Реальные LLM/mail/Telegram/webhook вызовы в тестах запрещены без отдельного разрешенного live этапа.
Результат: commit в ветке, docs/results/SP-E8-01C.md с выполненным scope, тестами, ограничениями и screenshots; submit переводит только в review. Done ставит интегратор после merge и проверки ancestry. Не обновлять золотые эталоны ради зеленых тестов.

## Материалы

Исходники только для чтения: C:/OSPanel/home/souzpir.local/.local/references/{eyon-pilot,laravel-prototype}.
Полный индекс изображений: planning/materials-manifest.json; точный состав по docs/sources/02b_mockup_journal.md.
