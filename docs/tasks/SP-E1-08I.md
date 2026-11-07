# SP-E1-08I · Уведомления: единая дневная сводка

**Зачем:** завершить S-7.1 по решению владельца и журналу макетов.
**Часть чего:** остаток SP-E1-08H, docs/results/SP-E1-08H.md.
**Зависимости:** SP-E1-08H в done.

Объединить seller и общую личную сводку одного получателя, бренда и канала в одну доставку в день; проверить доставку после тихих часов. Только fake-драйверы; живые отправки запрещены без разрешения.

Scope: `app/Shell/Notifications/`, `app/Seller/Settings/Limits/`, `app/Shell/Profile/`, `resources/js/pages/Shell/Profile/`, `tests/Feature/Shell/Notifications/`, `tests/Browser/E1-08I/`, `docs/results/SP-E1-08I.md`.

Приёмка: сценарий, права, бренд и ошибки; только fake вызовы. Остаток записать в docs/results/SP-E1-08I.md.
