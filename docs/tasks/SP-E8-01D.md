# SP-E8-01D · Мост опубликованных знаний в Видение

Прочитать AGENTS.md и docs/preparation/decisions.md. Выполнять только после разрешенного старта проекта и атомарного claim. Отдельный git worktree, не основной checkout.

Цель: Реализовать безопасное преобразование опубликованного, разрешённого для бренда knowledge release в атомы, ограничения и происхождение данных Видения. Повторная проверка tenant, версии, отзыва и назначения; никакого смешивания данных компаний, никаких сырьевых контактов в AI.

Зависимости (нужен done после merge): SP-E0-05C, SP-E3-01.
Область записи: app/Vision/KnowledgeBridge/, config/features/E8-01D.php, docs/modules/E8-01D.md, tests/Feature/Vision/KnowledgeBridge/, tests/Unit/Vision/KnowledgeBridge/, docs/results/SP-E8-01D.md.
Экраны: инфраструктура/контракты.
Источники: E8-01.
- E8-01: 03 §2.1; eyon `Services/Visibility/*`, `Audit/*`; R-V-05

Приёмка:
- Тесты изоляции брендов, отзыва/публикации, доказуемого provenance и отсутствия сырых контактов; только fake AI.

Дополнительно: Отдельно от Core и seller knowledge, согласовать DTO с SP-E8-01. SP-E8-01 не закрывать до сквозной интеграции.
Не изменять shared contracts/locks/чужие модели за границами scope. Если требуется расширение scope — передать интегратору точное изменение, не обходить ownership. Доменным задачам использовать loader и регистрацию, созданные фундаментом; новый общий dependency добавляет интегратор отдельно.
Проверить реальные UI → validation → Action → storage → runtime → policy → audit пути; фиктивный API не считается готовым. UI на русском, три темы, состояния loading/empty/error, 1440/1024/390 и доступность. C/D остаются честными ограничениями/«Скоро». Реальные LLM/mail/Telegram/webhook вызовы в тестах запрещены без отдельного разрешенного live этапа.
Результат: commit в ветке, docs/results/SP-E8-01D.md с выполненным scope, тестами, ограничениями и screenshots; submit переводит только в review. Done ставит интегратор после merge и проверки ancestry. Не обновлять золотые эталоны ради зеленых тестов.

## Материалы

Исходники только для чтения: C:/OSPanel/home/souzpir.local/.local/references/{eyon-pilot,laravel-prototype}.
Полный индекс изображений: planning/materials-manifest.json; точный состав по docs/sources/02b_mockup_journal.md.
