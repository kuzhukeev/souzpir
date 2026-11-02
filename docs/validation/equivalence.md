# SP-E3-07 · Проверка эквивалентности

## Состояние на 02.11.2026

| Проверка | Результат |
| --- | --- |
| T-01 v1, `tools/baseline/verify.py` | 41 случай, структура корректна; 29 persona без записи LLM, 12 синтетических manual |
| T-01 recorded-v2, `verify.py --require-recorded` | 41/41 запись донора, 29 persona и 12 manual, 824 вызова LLM, 2 автономных повтора, 0 пропусков |
| `RecordedBaselineInventoryTest` | IDs v1/v2 совпадают, файлы и SHA-256, два автономных повтора каждого случая |
| `ManualPolicyParityTest` | 10 синтетических manual сценариев Policy, 27 шагов: цель и outcome совпали с донором |
| 41 случай через новый runtime | Не запущено: runtime начала/продолжения диалога из SP-E3-08 ещё не готов |
| CI job `equivalence` | Не существует; изменение workflow и `phpunit.xml` за пределами scope |

**Вывод:** полная эквивалентность не доказана; число расхождений полного прогона не измерено. `equivalence_claim: false` в recorded-v2 относится только к автономному повтору донора, не к новому движку.

## Известные расхождения и пределы проверки

- При прямом вызове `Policy` без обвязки runtime состояние после `manual.closed_topic` отличается от trace: `introduced=false`/`stage=open` вместо `introduced=true`/`stage=qualify`. Цель и outcome совпадают. Нужно сверить полный runtime перед классификацией как дефект движка.
- Два manual сценария followup относятся к планировщику, а не к `Policy`, и не входят в 27 шагов теста.
- Тесты в `tests/Feature/Equivalence/` запускаются явным путём; текущий `phpunit.xml` их не подхватывает. Для критерия приёмки интегратору нужны CI job `equivalence`, подключение каталога и полный replay через SP-E3-08, затем фиксация подтверждённых расхождений в источнике 09.

## Команды

```text
python tools/baseline/verify.py --fixtures=tests/fixtures/prototype --prototype=<путь к laravel-prototype>
python tools/baseline/verify.py --fixtures=tests/fixtures/prototype/recorded-v2 --require-recorded --prototype=<путь к laravel-prototype>
php vendor/bin/phpunit tests/Feature/Equivalence --no-coverage
```
