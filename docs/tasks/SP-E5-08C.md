# SP-E5-08C · Экспорт клиентов CSV/XLSX в общий архив

Размер: 1–3 часа. Последнее функциональное продолжение D-3.5; дальше E5-08 не дробить.

**Зачем:** D-3.5 и S-7.4 требуют доступный экспорт клиентов в CSV/XLSX через общий архив. Сейчас экспортёр отключён feature flag и общий browser-тест ожидает его отсутствия.

**Часть чего:** SP-E5-08 после SP-E5-08B. Импорт и экран дублей уже выполнены; эта карточка закрывает оставшийся экспорт и не оставляет продолжений E5-08.

Зависимости: SP-E5-08B и SP-E1-11.

Работа: завершить CSV/XLSX-форматы экспорта контактов текущего бренда в общий архив S-7.4; ZIP экспорта продавца должен содержать CSV и XLSX, а существующий JSONL остаётся доступным для остальных модулей. Включить регистрацию экспортёра после готовности и обновить `tests/Browser/E1-11/data.spec.ts`, чтобы он проверял доступный экспорт. Дать ссылку на экспорт из экрана импорта/экспорта клиентов. Живые внешние сервисы не вызывать.

Scope: `app/Seller/Customers/ImportExport/CustomersExport.php`, `app/Seller/Customers/ImportExport/ImportExportController.php`, `app/Shell/Data/`, `config/features/E5-08.php`, `resources/js/pages/Seller/Customers/ImportExport/Index.tsx`, `resources/js/pages/Shell/Data/`, `routes/features/E5-08.php`, `tests/Browser/E1-11/data.spec.ts`, `tests/Browser/E5-08/`, `tests/Feature/Seller/Customers/ImportExport/`, `tests/Feature/Shell/Data/`, `docs/results/SP-E5-08C.md`. Сгенерированные типы разрешены общим правилом AGENTS.md.

Приёмка: CSV и XLSX скачиваются из архива для разрешённого бренда и не содержат чужих контактов; доступ и пустой экспорт проверены; E1-11 browser-тест обновлён; локальные затронутые тесты и Product проходят, CI ветки зелёный.
