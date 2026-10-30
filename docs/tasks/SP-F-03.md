# SP-F-03 · Каркас кабинета

Прочитать AGENTS.md и docs/preparation/decisions.md. Выполнять только после разрешенного старта проекта и атомарного claim. Отдельный git worktree, не основной checkout.

Цель: `CabinetLayout`: сайдбар 260/72, шапка 64, хлебные крошки, переключатель бренда, 11 пунктов модулей из конфигурации, «Скоро», активный пункт зелёный, мобильный drawer

Зависимости (нужен done после merge): SP-E0-08, SP-F-02.
Область записи: config/navigation.php, docs/design/layout.md, lang/ru/navigation.php, resources/js/components/navigation/, resources/js/layouts/, tests/Browser/Layout/, docs/results/SP-F-03.md.
Экраны: инфраструктура/контракты.
Источники: F-03.
- F-03: 06 §2, ТЗ §1.1; R-S-04, R-V-16

Приёмка:
- визуальные снапшоты 1440/1024/390; пункты меню = ТЗ §1.1

Дополнительно: Сохранять поведение донора; изменения только по принятым решениям.
Не изменять shared contracts/locks/чужие модели за границами scope. Если требуется расширение scope — передать интегратору точное изменение, не обходить ownership. Доменным задачам использовать loader и регистрацию, созданные фундаментом; новый общий dependency добавляет интегратор отдельно.
Проверить реальные UI → validation → Action → storage → runtime → policy → audit пути; фиктивный API не считается готовым. UI на русском, три темы, состояния loading/empty/error, 1440/1024/390 и доступность. C/D остаются честными ограничениями/«Скоро». Реальные LLM/mail/Telegram/webhook вызовы в тестах запрещены без отдельного разрешенного live этапа.
Результат: commit в ветке, docs/results/SP-F-03.md с выполненным scope, тестами, ограничениями и screenshots; submit переводит только в review. Done ставит интегратор после merge и проверки ancestry. Не обновлять золотые эталоны ради зеленых тестов.

## Материалы

Исходники только для чтения: C:/OSPanel/home/souzpir.local/.local/references/{eyon-pilot,laravel-prototype}.
Полный индекс изображений: planning/materials-manifest.json; точный состав по docs/sources/02b_mockup_journal.md.
