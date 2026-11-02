# SP-E4-06B · Импорт сайта в черновик знаний

**Зачем:** D-4.10, макет 58 и решение E8-01B: приватный черновик сайта должен давать выбираемые предложения фактов без публикации.

**Часть чего:** SP-E4-06 вносит TXT/CSV/XLSX и сохранение выбранных строк; здесь остаются SiteKnowledgeImporter, проверка отзыва доступа и XLSX fixture. PDF/DOCX показать как «Скоро» согласно исходной карточке; новых парсеров в этом продолжении нет.

Размер 1–3 часа. Зависимости: SP-E4-06, SP-E8-01B. Scope: `app/Seller/Knowledge/Imports/`, `resources/js/pages/Seller/Knowledge/Imports/`, `routes/features/E4-06.php`, `tests/Feature/Seller/Knowledge/Imports/`, `tests/Browser/E4-06/`, `docs/results/SP-E4-06B.md`. Не выполнять сеть в тестах. Проверить авторизацию, организацию/бренд, источник и явный выбор до записи в draft.
