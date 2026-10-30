# SP-E5-04B · Мои диалоги, суфлёр и пометки

Прочитать AGENTS.md и docs/preparation/decisions.md. Выполнять только после разрешенного старта проекта и атомарного claim. Отдельный git worktree, не основной checkout.

Цель: D-2.3 мобильный сценарий менеджера; D-2.4 очередь подсказок с редактированием/отправкой; D-2.5 пометки по словарю А.6. Использовать единый Dialog view и API первой части.

Зависимости (нужен done после merge): SP-E5-04, SP-F-04.
Область записи: app/Seller/Dialogs/, config/features/E5-04B.php, database/factories/Seller/Dialogs/, database/migrations/E5-04B/, database/seeders/Seller/Dialogs/, docs/modules/E5-04B.md, lang/ru/Seller/Dialogs/, resources/js/features/Seller/Dialogs/, resources/js/pages/Seller/Dialogs/, resources/js/types/Seller/Dialogs/, routes/features/E5-04B.php, tests/Browser/E5-04B/, tests/Feature/Seller/Dialogs/, tests/Unit/Seller/Dialogs/, docs/results/SP-E5-04B.md.
Экраны: D-2.3, D-2.4, D-2.5.
Источники: E5-04.
- E5-04: ТЗ D-2.1–D-2.5; макеты 39–43; R-D-01, R-D-02, R-D-38

Приёмка:
- Проверены manager/suggest, mobile 390, races на присвоение и отправку подсказки, polling и очистка brand cache.

Дополнительно: Сохранять поведение донора; изменения только по принятым решениям.
Не изменять shared contracts/locks/чужие модели за границами scope. Если требуется расширение scope — передать интегратору точное изменение, не обходить ownership. Доменным задачам использовать loader и регистрацию, созданные фундаментом; новый общий dependency добавляет интегратор отдельно.
Проверить реальные UI → validation → Action → storage → runtime → policy → audit пути; фиктивный API не считается готовым. UI на русском, три темы, состояния loading/empty/error, 1440/1024/390 и доступность. C/D остаются честными ограничениями/«Скоро». Реальные LLM/mail/Telegram/webhook вызовы в тестах запрещены без отдельного разрешенного live этапа.
Результат: commit в ветке, docs/results/SP-E5-04B.md с выполненным scope, тестами, ограничениями и screenshots; submit переводит только в review. Done ставит интегратор после merge и проверки ancestry. Не обновлять золотые эталоны ради зеленых тестов.

## Материалы

Исходники только для чтения: C:/OSPanel/home/souzpir.local/.local/references/{eyon-pilot,laravel-prototype}.
Полный индекс изображений: planning/materials-manifest.json; точный состав по docs/sources/02b_mockup_journal.md.

- `C:\OSPanel\home\tasks.local\completed\41\011b1773-D-2.3-mobile-flow.png`
- `C:\OSPanel\home\tasks.local\completed\41\36c3e5d3-D-2.3-empty.png`
- `C:\OSPanel\home\tasks.local\completed\41\5d577125-D-2.3-mine.png`
- `C:\OSPanel\home\tasks.local\completed\42\32cdca94-D-2.4-empty.png`
- `C:\OSPanel\home\tasks.local\completed\42\3c8423fd-D-2.4-queue.png`
- `C:\OSPanel\home\tasks.local\completed\42\7d5e41e5-D-2.4-mobile.png`
- `C:\OSPanel\home\tasks.local\completed\43\5432cfb3-D-2.5-empty.png`
- `C:\OSPanel\home\tasks.local\completed\43\80937148-D-2.5-main.png`
- `C:\OSPanel\home\tasks.local\completed\43\ba7becf3-D-2.5-states.png`
