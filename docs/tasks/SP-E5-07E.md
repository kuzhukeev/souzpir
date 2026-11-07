# SP-E5-07E · Почта: переход к настройке

**Зачем:** завершение экранов по решению владельца и журналу макетов.
**Часть чего:** остаток SP-E5-07D, docs/results/SP-E5-07D.md.
**Зависимости:** SP-E5-07D.

## Приёмка
- D-9.8: доступный переход из общей навигации к настройке почты с учётом прав и модулей; проверить браузером и сверить с макетом, не включать живую почту.
- Проверить основной сценарий, права, изоляцию брендов, ошибки и макет; живые внешние вызовы отключены. Остаток оформить по AGENTS.md.

Scope: `resources/js/components/navigation/Sidebar.tsx`, `resources/js/components/navigation/navigation.json`, `resources/js/pages/Seller/Channels/Email/`, `tests/Browser/E5-07E/`, `docs/results/SP-E5-07E.md`.

## Конкретный остаток SP-E5-02E
- D-9.6 и D-9.7: заменить стилизованные буквы VK и составную пиктограмму телефона на соответствующие фирменные знаки по макетам; проверить положение и назначение кнопки интереса.

- D-9.8: в исходном виде по макету не показывать встроенную форму тестового письма и карточку прайс-файла; сохранить доступ к обоим действиям, открывая форму теста кнопкой в шапке, с полем адресата и без живой отправки.

Дополнительный scope: `resources/js/pages/Seller/Channels/Planned/Index.tsx`, `resources/js/features/Seller/Channels/Planned/Planned.test.tsx`.

Навигация использует Sidebar.tsx и navigation.json; занятый переключатель BrandSwitch.tsx не изменять. Дополнительный свободный scope полного пути: `routes/features/E5-07.php`, `app/Seller/Channels/Email/EmailSettingsController.php`, `tests/Feature/Seller/Channels/Email/EmailSettingsTest.php`, `tests/Feature/Seller/Channels/Email/EmailSettingsE507CTest.php`.
