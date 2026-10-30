# SP-E8-05B · Конкуренты и площадки

Прочитать AGENTS.md и docs/preparation/decisions.md. Выполнять только после разрешенного старта проекта и атомарного claim. Отдельный git worktree, не основной checkout.

Цель: V-6.1–6.4: сравнение и дедупликация конкурентов; V-7.1–7.3: SOURCE_TYPES, источники и рекомендации площадок. Сохранить определения метрик.

Зависимости (нужен done после merge): SP-E8-05, SP-F-03.
Область записи: app/Vision/Competitors/, app/Vision/Sources/, config/features/E8-05B.php, database/factories/Vision/Competitors/, database/factories/Vision/Sources/, database/migrations/E8-05B/, database/seeders/Vision/Competitors/, database/seeders/Vision/Sources/, docs/modules/E8-05B.md, lang/ru/Vision/Competitors/, lang/ru/Vision/Sources/, resources/js/features/Vision/Competitors/, resources/js/features/Vision/Sources/, resources/js/pages/Vision/Competitors/, resources/js/pages/Vision/Sources/, resources/js/types/Vision/Competitors/, resources/js/types/Vision/Sources/, routes/features/E8-05B.php, tests/Browser/E8-05B/, tests/Feature/Vision/Competitors/, tests/Feature/Vision/Sources/, tests/Unit/Vision/Competitors/, tests/Unit/Vision/Sources/, docs/results/SP-E8-05B.md.
Экраны: V-6.1, V-6.2, V-6.3, V-6.4, V-7.1, V-7.2, V-7.3.
Источники: E8-05.
- E8-05: ТЗ V-5.1–V-5.10, V-6.1–V-6.4, V-7.1–V-7.3; макеты 105–114; R-V-09, R-V-10

Приёмка:
- Дедуп атомарен, метрики на fixtures; CRUD/isolation, empty/error/loading, visual.

Дополнительно: Сохранять поведение донора; изменения только по принятым решениям.
Не изменять shared contracts/locks/чужие модели за границами scope. Если требуется расширение scope — передать интегратору точное изменение, не обходить ownership. Доменным задачам использовать loader и регистрацию, созданные фундаментом; новый общий dependency добавляет интегратор отдельно.
Проверить реальные UI → validation → Action → storage → runtime → policy → audit пути; фиктивный API не считается готовым. UI на русском, три темы, состояния loading/empty/error, 1440/1024/390 и доступность. C/D остаются честными ограничениями/«Скоро». Реальные LLM/mail/Telegram/webhook вызовы в тестах запрещены без отдельного разрешенного live этапа.
Результат: commit в ветке, docs/results/SP-E8-05B.md с выполненным scope, тестами, ограничениями и screenshots; submit переводит только в review. Done ставит интегратор после merge и проверки ancestry. Не обновлять золотые эталоны ради зеленых тестов.

## Материалы

Исходники только для чтения: C:/OSPanel/home/souzpir.local/.local/references/{eyon-pilot,laravel-prototype}.
Полный индекс изображений: planning/materials-manifest.json; точный состав по docs/sources/02b_mockup_journal.md.

- `C:\OSPanel\home\tasks.local\completed\108\9e5154af-V-6.1-mentioned-empty.png`
- `C:\OSPanel\home\tasks.local\completed\108\baacfe85-V-6.1-mentioned.png`
- `C:\OSPanel\home\tasks.local\completed\109\505d259f-V-6.2-competitor-top.png`
- `C:\OSPanel\home\tasks.local\completed\109\718183c4-V-6.2-competitor-details.png`
- `C:\OSPanel\home\tasks.local\completed\110\1f71e876-V-6.3-recalculating.png`
- `C:\OSPanel\home\tasks.local\completed\110\3f84ee57-V-6.3-main.png`
- `C:\OSPanel\home\tasks.local\completed\110\7e482869-V-6.3-merge.png`
- `C:\OSPanel\home\tasks.local\completed\110\d6b8fb13-V-6.3-empty.png`
- `C:\OSPanel\home\tasks.local\completed\111\4f5bcfaf-V-6.4-compare.png`
- `C:\OSPanel\home\tasks.local\completed\112\7bd46959-V-7.1-sources.png`
- `C:\OSPanel\home\tasks.local\completed\112\8241102a-V-7.1-sources-empty.png`
- `C:\OSPanel\home\tasks.local\completed\113\06a0d3dd-V-7.2-states.png`
- `C:\OSPanel\home\tasks.local\completed\113\3c07fb3b-V-7.2-main.png`
- `C:\OSPanel\home\tasks.local\completed\114\8cd58c0c-V-7.3-recommended-sources.png`
