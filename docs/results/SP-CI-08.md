# SP-CI-08 — race после подготовки БД

## Что сделано

Устранён запрещённый откат E1-08 при teardown четырёх race-классов: изолированную базу очищает `IsolatedDatabaseMigrations`. Обновлён устаревший password-reset fixture под одноразовый grant из фрагмента. Тест защищённой истории проверяет точный SQLSTATE запрета `TRUNCATE` и откатывает зависимую таблицу до миграции родительской. У security worker добавлена актуальная session-brand selection; ожидание второго устройства привязано к реальной блокировке БД.

## Одиннадцать падений run 41412123385

| Тест | Причина | Что сделано |
| --- | --- | --- |
| `ExportLogoutRaceTest::test_logout_does_not_wait_for_blocked_download_and_stops_subsequent_chunks` | в: teardown откатывал необратимую E1-08 | Изолированная очистка БД |
| `SecurityConcurrencyTest::test_two_devices_cannot_deadlock_or_undo_each_others_revocation` (one) | в: fixture не указывал выбранный бренд и ревизию, ProfileAccess возвращал 409 | Инициализирована session-brand selection; ожидание `pg_locks` |
| `SecurityConcurrencyTest::test_two_devices_cannot_deadlock_or_undo_each_others_revocation` (all) | в: та же неполная fixture, 409 | Инициализирована session-brand selection; ожидание `pg_locks` |
| `FirstTurnStateTest::test_responder_history_tampering_and_concurrent_input_are_rejected` | в: FK запрещает TRUNCATE с SQLSTATE 0A000 | Проверяется точный код 0A000 отдельно от запрета мутаций 23514 |
| `FirstTurnStateTest::test_responder_migration_preserves_existing_turn_and_refuses_to_discard_history` | в: родительскую таблицу нельзя удалить до дочерней | Удаление и восстановление зависимой миграции |
| `SessionRoutingRaceTest::test_real_mutation_and_router_do_not_deadlock` (rules) | в: teardown откатывал E1-08 | Изолированная очистка БД |
| `SessionRoutingRaceTest::test_real_mutation_and_router_do_not_deadlock` (time) | в: teardown откатывал E1-08 | Изолированная очистка БД |
| `SessionRoutingRaceTest::test_real_mutation_and_router_do_not_deadlock` (test) | в: teardown откатывал E1-08 | Изолированная очистка БД |
| `SessionRoutingRaceTest::test_password_reset_waits_for_active_action_then_invalidates_old_version` | в: fixture вызывал устаревший POST с сырым token | Формируется действующий session grant и v2-хеш |
| `SessionRoutingRaceTest::test_concurrent_version_change_first_denies_the_old_session_action` | в: teardown откатывал E1-08 | Изолированная очистка БД |
| `Tenancy/ConcurrencyTest::test_two_real_connections_can_consume_a_token_only_once` | в: teardown откатывал E1-08 | Изолированная очистка БД |

## Проверки

PHP lint и `git diff --check` — зелёные. Итоговый `workflow_dispatch` на ветке, run 41428683907: 169 passed, 0 failed, 3 ранее пропускавшихся, 2205 assertions; обычный CI тоже зелёный. Повторный запуск на main — после слияния.

## Техдолг

Нет.

## Принято по умолчанию

Нет.
