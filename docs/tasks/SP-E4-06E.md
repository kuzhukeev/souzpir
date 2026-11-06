# SP-E4-06E · Импорт знаний: лист XLSX и экран документов

**Зачем:** завершить экран D-4.10 по макету.
**Часть чего:** остаток SP-E4-06C, `docs/results/SP-E4-06C.md`.
**Зависимости:** SP-E4-06D в `done`.

## Приёмка
- Добавить выбор листа XLSX при импорте; точечное изменение общего `app/Seller/Catalog/CatalogReader.php` разрешено с сохранением существующего поведения каталога.
- Показать список документов и сверить двухколоночную компоновку, источник каждого фрагмента, пустое состояние, загрузку и ошибки с макетом D-4.10.
- Проверить права, изоляцию брендов, неправильный файл и выбранный лист, а также отсутствие регрессии существующих сценариев каталога; только fake runtime.

Scope: `app/Seller/Knowledge/Imports/`, `app/Seller/Catalog/CatalogReader.php`, `routes/features/E4-06.php`, `resources/js/pages/Seller/Knowledge/Imports/`, `tests/Feature/Seller/Knowledge/Imports/`, `tests/Feature/Seller/Catalog/`, `tests/Browser/E4-06E/`, `docs/results/SP-E4-06E.md`.
