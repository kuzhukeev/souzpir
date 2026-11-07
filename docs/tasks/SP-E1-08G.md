# SP-E1-08G · Уведомления: тихие часы и сводка

**Зачем:** завершить S-7.1 по решению владельца и журналу макетов.
**Часть чего:** остаток SP-E1-08F, docs/results/SP-E1-08F.md.
**Зависимости:** SP-E1-08F в done.

Подключить сохранённые тихие часы и ежедневную сводку к существующей доставке email и Telegram с учётом бренда и часового пояса. Только fake-драйверы, живые отправки без разрешения запрещены.

Scope: `app/Shell/Notifications/`, `app/Seller/Settings/Limits/`, `app/Shell/Profile/`, `resources/js/pages/Shell/Profile/`, `tests/Feature/Shell/Notifications/`, `tests/Browser/E1-08G/`, `docs/results/SP-E1-08G.md`.

Приёмка: основной сценарий, права, бренд и ошибки; только fake внешние вызовы. Остаток записать в docs/results/SP-E1-08G.md.
