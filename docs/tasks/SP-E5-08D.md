# SP-E5-08D · Клиенты: экспорт и шаблон импорта

**Зачем:** решение владельца от 03.11.2026 закрыть незапланированные расхождения с макетами по итогам аудита 160 экранов; экраны D-3.5.
**Часть чего:** остаток после SP-E5-08/08C.
**Зависимости:** SP-E5-05E (дождаться merge).

- Экран D-3.5 «Импорт/экспорт» соответствует макету из матрицы по пунктам (журнал docs/sources/02b_mockup_journal.md [48]): экспорт делегирован в /settings/data (нет Период/Стадии/Формат/«Подготовить»); нет шаблона CSV. Где сейчас: ImportExport/Index.tsx:11–13.
- Каждый пункт покрыт Feature- или Browser-тестом; данные только текущего бренда, проверены права ролей; состояния пусто / загрузка / ошибка как на макете; в отчёте скриншоты каждого экрана рядом с макетом.
- Без живых и платных внешних вызовов без разрешения владельца; если пункт макета противоречит ТЗ или требует решения владельца, исполнитель не выдумывает, а записывает вопрос в docs/decisions-pending.md и делает остальное.

Scope: `app/Seller/Customers/`, `resources/js/pages/Seller/Customers/`, `routes/features/E5-08.php`, `tests/Feature/Seller/Customers/`, `tests/Browser/E5-08D/`, `docs/results/SP-E5-08D.md`.

Отчёт: docs/results/SP-E5-08D.md; локальные тесты задачи, submit и зелёный CI.


## Остаток 08.11.2026 (планировщик, запуск 20261107-225307)
- D-3.4 (макет `mockups D-3.4`, `05da310d-D-3.4-duplicates.webp`): select «Все совпадения» (телефон / почта / имя и город) рядом с поиском, плашка «Совпадения по телефону, почте или имени и городу»; фильтр работает на сервере или по уже переданным данным.
- D-3.5 (макет `mockups D-3.5`): зона перетаскивания CSV (dropzone) с кнопкой выбора файла; валидация прежняя.
- `app/Seller/Customers/CustomersController.php` занят SP-E5-05F — не трогать; нужен — «Нужно от интегратора».
- Права и изоляция брендов, ошибка формата файла; 1440/390, клавиатура, axe.

Scope: `resources/js/pages/Seller/Customers/ImportExport/Duplicates.tsx`, `resources/js/pages/Seller/Customers/ImportExport/Index.tsx`, `app/Seller/Customers/ImportExport/ImportExportController.php`, `tests/Feature/Seller/Customers/ImportExport/DuplicateFilterE508DTest.php`, `tests/Browser/E5-08D/`, `docs/results/SP-E5-08D.md`.
