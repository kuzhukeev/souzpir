# SP-E7-02G · Групповые прогоны: fake-оценка

**Зачем:** завершить D-7.6 по решению владельца и журналу макетов.
**Часть чего:** остаток частичной сдачи SP-E7-02F, docs/results/SP-E7-02F.md.
**Зависимости:** SP-E7-02F в done.

Обеспечить доступную fake-оценку произвольных прогонов без изменения golden fixtures; добавить стоимость и выбор модели только после утверждения данных и поддержки runtime. Для изменений общего fake Judge обратиться к интегратору.

Scope: `app/Seller/Scenarios/`, `app/Seller/Evaluation/`, `resources/js/pages/Seller/Scenarios/`, `routes/features/E7-02.php`, `tests/Feature/Seller/Scenarios/`, `tests/Browser/E7-02G/`, `docs/results/SP-E7-02G.md`.

Приёмка: сценарий, права, изоляция брендов и ошибки; только fake внешние вызовы. Остаток записать в docs/results/SP-E7-02G.md.
