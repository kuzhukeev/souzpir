# SP-E5-02C · Виджет: сессия посетителя и сообщения

**Размер:** 1–3 часа; функциональный остаток, не сверх ТЗ.

**Зачем:** Backlog E5-02 и экраны D-9.2, D-9.3, D-9.4; обязательный остаток после частичной сдачи SP-E5-02.

**Часть чего:** SP-E5-02. После C функционального остатка SP-E5-02 нет.

Зависимости: SP-E5-02B, SP-E3-08.

Scope: `app/Seller/Channels/Widget/`, `resources/widget/`, `resources/js/pages/Seller/Channels/Widget/`, `routes/features/E5-02.php`, `tests/Browser/E5-02/`, `tests/Feature/Seller/Channels/Widget/`, `tests/Unit/Seller/Channels/Widget/`, `config/features/E5-02.php`, `database/migrations/E5-02C/`, `resources/js/types/Seller/Channels/Widget/`, `docs/results/SP-E5-02C.md`. Сгенерированные типы разрешены общим правилом.

Связать защищённую сессию посетителя и сообщения виджета с seller runtime и сохранённым согласием/контактом.

Приёмка: Fake/HTTP/browser e2e №7: начало сессии, ход, повтор, чужой origin и бренд, недоступный runtime. Живые внешние вызовы в тестах запрещены.

Чужие модули не менять; итоговый отчёт до 30 строк с тестами, долгом и решениями.
