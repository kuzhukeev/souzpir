# SP-E4-03F · Условия и выбор подстановок

**Зачем:** конкретные незакрытые пункты экрана D-4.5 по ТЗ.
**Часть чего:** остаток SP-E4-03E, docs/results/SP-E4-03E.md.
**Зависимости:** SP-E4-03E.

## Приёмка
- D-4.5: добавить min_order_quantity, prepayment_pct, availability и other_conditions в выбор подстановок редактора факта; проверить выбор, сохранение, подстановку и показ связанного факта.
- D-4.5: добавить отсутствующие пиктограммы и контекстные меню карточек по макету с доступностью клавиатуры.
- Проверить права, изоляцию брендов, ошибки и макет; использовать изолированные тестовые данные.

Scope: `app/Seller/Knowledge/Facts/FactsController.php`, `resources/js/pages/Seller/Knowledge/Conditions/`, `tests/Feature/Seller/Knowledge/Facts/`, `tests/Browser/E4-03F/`, `docs/results/SP-E4-03F.md`.
