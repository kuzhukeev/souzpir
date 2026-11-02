# SP-E5-02B · Виджет: согласие, контакт и приглашения

**Размер:** 1–3 часа; функциональный остаток, не сверх ТЗ.

**Зачем:** Backlog E5-02 и экраны D-9.2, D-9.3, D-9.4; обязательный остаток после частичной сдачи SP-E5-02.

**Часть чего:** SP-E5-02. SP-E5-02C подключит защищённую сессию и сообщения к runtime и закроет e2e №7.

Зависимости: SP-E5-02.

Scope: `app/Seller/Channels/Widget/`, `resources/widget/`, `resources/js/pages/Seller/Channels/Widget/`, `tests/Browser/E5-02/`, `tests/Feature/Seller/Channels/Widget/`, `tests/Unit/Seller/Channels/Widget/`, `config/features/E5-02.php`, `database/migrations/E5-02B/`, `routes/features/E5-02.php`, `resources/js/types/Seller/Channels/Widget/`, `docs/results/SP-E5-02B.md`. Сгенерированные типы разрешены общим правилом.

Доделать видимые состояния виджета: согласие, сбор контакта и недостающие триггеры приглашения без живой переписки.

Приёмка: Fake/HTTP/browser: согласие до контакта, ошибки ввода, таймер, прокрутка, повтор показа, разрешённый origin. Живые внешние вызовы в тестах запрещены.

Чужие модули не менять; итоговый отчёт до 30 строк с тестами, долгом и решениями.
