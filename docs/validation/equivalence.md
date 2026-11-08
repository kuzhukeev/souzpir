# SP-E3-07 · Проверка эквивалентности

## Состояние на 02.11.2026

| Проверка | Результат |
| --- | --- |
| T-01 v1, `tools/baseline/verify.py` | 41 случай, структура корректна; 29 persona без записи LLM, 12 синтетических manual |
| T-01 recorded-v2, `verify.py --require-recorded` | 41/41 запись донора, 29 persona и 12 manual, 824 вызова LLM, 2 автономных повтора, 0 пропусков |
| `RecordedBaselineInventoryTest` | IDs v1/v2 совпадают, файлы и SHA-256, два автономных повтора каждого случая |
| `ManualPolicyParityTest` | 10 синтетических manual сценариев Policy, 27 шагов: цель и outcome совпали с донором |
| 41 случай через новый runtime | Не запущено: runtime начала/продолжения диалога из SP-E3-08 ещё не готов |
| CI job `equivalence` | Выделен отдельный набор: инвентаризация 41 случая, контроль 824 записанных вызовов и частичный replay Policy |

**Вывод:** полная эквивалентность не доказана; число расхождений полного прогона не измерено. `equivalence_claim: false` в recorded-v2 относится только к автономному повтору донора, не к новому движку.

## Известные расхождения и пределы проверки

- При прямом вызове `Policy` без обвязки runtime состояние после `manual.closed_topic` отличается от trace: `introduced=false`/`stage=open` вместо `introduced=true`/`stage=qualify`. Цель и outcome совпадают. Нужно сверить полный runtime перед классификацией как дефект движка.
- Два manual сценария followup относятся к планировщику, а не к `Policy`, и не входят в 27 шагов теста.
- Тесты подключены к отдельному набору Equivalence и job `equivalence`; зелёный job подтверждает только перечисленные частичные проверки, не эквивалентность runtime. Полный replay остаётся незавершённым: последующие ходы текущего защищённого runtime возвращают фиксированный fake-ответ, а изменение runtime вне scope этой карточки. Подтверждённые расхождения ещё не измерены и не внесены в источник 09.

## Команды

```text
python tools/baseline/verify.py --fixtures=tests/fixtures/prototype --prototype=<путь к laravel-prototype>
python tools/baseline/verify.py --fixtures=tests/fixtures/prototype/recorded-v2 --require-recorded --prototype=<путь к laravel-prototype>
php vendor/bin/phpunit --testsuite=Equivalence --no-coverage
```

## SP-E3-07D: офлайн-адаптер

Адаптер обрабатывает ответы без текста при непустых вызовах инструментов. Проверены последовательно 824 записанных вызова во всех 41 сценарии, в том числе 65 ответов только с инструментами; исчерпанная запись отклоняется. Это проверка адаптера на записанных запросах, а не replay нового runtime и не подтверждение эквивалентности. Тест автоматически включён в существующий набор Equivalence в CI.

## SP-E3-07G: рабочий replay (08.11.2026, реопен с расширенным scope)

Полный разбор по каждому кейсу — `docs/validation/SP-E3-07G-runtime-replay.md`. Итог:

- **29 `persona.*` через `ApiOpeningRuntime`** — закрыто: `ApiOpeningInput.php:70` передавал пустой список
  инструментов вместо `$goal->allowedTools`; после правки все 29 кейсов проходят байт-в-байт, включая `tools`.
- **`followup_40h`** — закрыто: `FollowupSender.php`/`PrepareFollowups.php` считали «последнее сообщение агента»
  (базу отсчёта тишины) по всем агентским сообщениям, включая собственные напоминания; второй порог
  `FollowupPolicy::due()` отмерял от первого напоминания, а не от начала молчания (60ч вместо 40ч). Исключили
  сообщения с `meta.goal.kind = followup_nudge` из этого запроса — теперь совпадает с записью на всех шагах.
- **Стадия `qualify` в карточке responder'а (9 диалоговых + no_repeat_filled_slot)**: гипотеза `FirstTurnGoal.php:52`
  `isFirstTurn=false→true` проверена и отвергнута — ломает пинованный CI-тест `FirstTurnStateTest::test_first_client_message_follows_donor_inbound_goal_not_proactive_opening`,
  который осознанно требует `ask_slot`/`answer_and_ask`, а не `intro_and_ask`, для первого сообщения клиента (опенинг
  в проде — отдельный механизм, не эта ветка `Policy::decide()`). Правка откатана, мismatch `open` вместо `qualify`
  остаётся прежним — донорские трейсы DIALOGUE_CASES сами предполагают уже прошедший опенинг.
- **`manual.force_close_14`**: воспроизводится как остальные 8 диалоговых кейсов — спотыкается на той же стадии до
  периодического `summary`-вызова.
- Тест (`tests/Equivalence/RuntimeReplayE307GTest.php`) зарегистрирован в `phpunit.xml` → testsuite `Equivalence`,
  CI его запускает.
