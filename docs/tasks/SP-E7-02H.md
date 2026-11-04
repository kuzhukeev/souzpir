# SP-E7-02H · Групповые прогоны: оценка без точной фикстуры

**Зачем:** завершить D-7.6 по решению владельца и журналу макетов.
**Часть чего:** остаток SP-E7-02G, docs/results/SP-E7-02G.md.
**Зависимости:** SP-E7-02G в done.

Определить и реализовать детерминированный fake Judge для произвольных диалогов без изменения golden fixtures; проверить агрегированную оценку, права и изоляцию. Стоимость и выбор модели добавлять только после утверждения данных.

Scope: `app/Seller/Evaluation/`, `app/Seller/Scenarios/`, `resources/js/pages/Seller/Scenarios/`, `tests/Feature/Seller/Scenarios/`, `tests/Feature/Seller/Evaluation/`, `tests/Browser/E7-02H/`, `docs/results/SP-E7-02H.md`.

Приёмка: основной сценарий, права, бренд и ошибки; только fake вызовы. Остаток записать в docs/results/SP-E7-02H.md.
