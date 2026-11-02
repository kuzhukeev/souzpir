# SP-E7-03B · Калибровка оценок прогонов

**Зачем:** D-7.8, макет 143: пользователь должен сопоставить оценки человека и судьи и сохранить калибровку.

**Часть чего:** SP-E7-03 вносит сравнение пары оценённых прогонов D-7.7; здесь остаются `judge_calibrations` и экран/HTTP калибровки. После этой карточки остатка E7-03 нет.

Размер 1–3 часа. Зависимости: SP-E7-03. Scope: `app/Seller/Calibration/`, `database/migrations/E7-03B/`, `resources/js/pages/Seller/Calibration/`, `routes/features/E7-03.php`, `tests/Feature/Seller/Calibration/`, `tests/Browser/E7-03B/`, `docs/results/SP-E7-03B.md`. Проверить сохранение, права, бренд, ошибку ввода и сравнение на fixture; без живых вызовов ИИ.
