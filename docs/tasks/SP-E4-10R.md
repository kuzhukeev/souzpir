# SP-E4-10R · Песочница: тема и применённые приёмы

Остаток SP-E4-10H, D-4.11: показать распознанную тему и фактически выбранные и применённые приёмы выбранного хода, используя реальные данные защищённого runtime. Не подменять применённые приёмы рекомендованными тегами. Проверить несколько ходов и отсутствие данных; fake AI, изоляция брендов и права.

Scope: app/Seller/Sandbox/SandboxRuntime.php, app/Seller/Sandbox/SandboxController.php, resources/js/pages/Seller/Sandbox/Index.tsx, tests/Browser/E4-10H/inspector.spec.ts, tests/Browser/E4-10H/fixture.tsx, docs/results/SP-E4-10R.md. Перед выдачей уточнить точные файлы источника данных и ownership shared-ai-stages.


## Остаток 08.11.2026 (планировщик, запуск 20261107-225307)
- D-4.11 (макет `mockups D-4.11`): «Проверка ответа» и тестовый диалог работают и для опубликованной версии знаний (сейчас `SandboxController` для не-черновика возвращает `unavailable`, строка ~58); выбор версии в select уже есть.
- Плашка «Тестовые диалоги не попадают в статистику» над диалогом.
- Кнопка «Сохранить как контрольный сценарий» — подпись по макету (действие уже есть).
- Fake AI, права и изоляция брендов; тест на опубликованную версию и на чужой бренд.

Scope: `app/Seller/Sandbox/SandboxController.php`, `resources/js/pages/Seller/Sandbox/Index.tsx`, `tests/Feature/Seller/Sandbox/PublishedReleaseE410RTest.php`, `tests/Browser/E4-10R/`, `docs/results/SP-E4-10R.md`.
