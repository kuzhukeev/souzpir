Модель: Sonnet 5
# SP-E6-03W — Реакции: сквозные сценарии отказа

Остаток 07.11.2026 23:50: D-5.6 «Коротких ответов N» (дополнение владельца от 05.11.2026, карточка D-5.6-offtopic.webp).

- Порог переключения `decline_off_topic` → `decline_off_topic_second` вынесен из захардкоженного `>= 2` в настраиваемый `$this->limit('decline_off_topic_second_after', 2)` (`app/Seller/Engine/Policy.php:293-305`), по образцу остальных лимитов политики.
- `OffTopicController::save` принимает и сохраняет `decline_off_topic_second_after` (1–10) в `policy.limits` черновика базы знаний.
- Поле «Коротких ответов» добавлено на экран D-5.6 (`resources/js/pages/Seller/Settings/Policy/Index.tsx`, секция «Вне темы и негатив») перед «Сообщений вне темы подряд» — порядок совпадает с макетом `mockups/137/D-5.6-offtopic.webp`.
- Проверено по макету: «Коротких ответов 2» в карточке «Сообщения не по делу» — поле добавлено с тем же лейблом и позицией (без «Фразы возврата к делу» — она закрыта ранее через ReactionExamples).
- Тесты: `OffTopicPolicyE603CTest::test_decline_off_topic_second_after_is_configurable` (новый, PHPUnit) — порог 1 переключает тег и слот сразу после первого отказа; `OffTopicTest` обновлён (сохранение/изоляция/валидация с новым полем) — зелёные. Полный `tests/Feature/Seller/Runtime`, `tests/Unit/Seller/Engine`, `tests/Feature/Seller/Settings/Policy`, `tests/Feature/Seller/Channels/Api` (160 проходят; 1 тест `FieldRequestE603RTest` падает по инфраструктурной причине — «dedicated test database owner» в слоте, не связано с правкой). Browser: новый `tests/Browser/E6-03W/off-topic.spec.ts` (Vite-фикстура, свой порт) — поле читает/сохраняет значение; скриншот 1440 приложен.
- `npm run lint/typecheck/test/build` — зелёные.

## Техдолг
- Нет новых рисков сверх уже описанных в прошлой сдаче.

## Принято по умолчанию
- Дефолт лимита при отсутствии в снапшоте — 2 (прежнее зашитое поведение), как и у остальных `limits.*`.
