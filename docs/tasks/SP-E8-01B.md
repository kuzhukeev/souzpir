# SP-E8-01B · Ядро аудита сайта

Прочитать AGENTS.md и docs/preparation/decisions.md. Выполнять только после разрешенного старта проекта и атомарного claim. Отдельный git worktree, не основной checkout.

Цель: Перенести SiteInspector и SiteKnowledgeImporter с сетевыми ограничениями/таймаутами, fixtures HTML и маппингом 16+4 проверок. До Q-03 сохранять группы и смысл кода, не менять scoring ради макета.

Зависимости (нужен done после merge): SP-C-01, SP-E0-05.
Область записи: app/Vision/SiteAudit/, config/features/E8-01B.php, database/factories/Vision/SiteAudit/, database/migrations/E8-01B/, database/seeders/Vision/SiteAudit/, docs/modules/E8-01B.md, lang/ru/Vision/SiteAudit/, resources/js/features/Vision/SiteAudit/, resources/js/pages/Vision/SiteAudit/, resources/js/types/Vision/SiteAudit/, routes/features/E8-01B.php, tests/Browser/E8-01B/, tests/Feature/Vision/SiteAudit/, tests/Unit/Vision/SiteAudit/, docs/results/SP-E8-01B.md.
Экраны: инфраструктура/контракты.
Источники: E8-01.
- E8-01: 03 §2.1; eyon `Services/Visibility/*`, `Audit/*`; R-V-05

Приёмка:
- HTML fixtures, HTTP fake, отсутствие сетевых вызовов в tests; критерии/веса/границы описаны.

Дополнительно: Сохранять поведение донора; изменения только по принятым решениям.
Не изменять shared contracts/locks/чужие модели за границами scope. Если требуется расширение scope — передать интегратору точное изменение, не обходить ownership. Доменным задачам использовать loader и регистрацию, созданные фундаментом; новый общий dependency добавляет интегратор отдельно.
Проверить реальные UI → validation → Action → storage → runtime → policy → audit пути; фиктивный API не считается готовым. UI на русском, три темы, состояния loading/empty/error, 1440/1024/390 и доступность. C/D остаются честными ограничениями/«Скоро». Реальные LLM/mail/Telegram/webhook вызовы в тестах запрещены без отдельного разрешенного live этапа.
Результат: commit в ветке, docs/results/SP-E8-01B.md с выполненным scope, тестами, ограничениями и screenshots; submit переводит только в review. Done ставит интегратор после merge и проверки ancestry. Не обновлять золотые эталоны ради зеленых тестов.

## Материалы

Исходники только для чтения: C:/OSPanel/home/souzpir.local/.local/references/{eyon-pilot,laravel-prototype}.
Полный индекс изображений: planning/materials-manifest.json; точный состав по docs/sources/02b_mockup_journal.md.
