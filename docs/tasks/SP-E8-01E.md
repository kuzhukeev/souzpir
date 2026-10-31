# SP-E8-01E · Необратимый отзыв доступа к знаниям

Прочитать AGENTS.md и docs/preparation/decisions.md. Выполнять только после разрешенного старта проекта и атомарного claim. Отдельный git worktree, не основной checkout.

Цель: Создать постоянный реестр выдач Vision knowledge: ULID grant, tenant/brand/release, purpose=vision, knowledge_public, policy checksum и однонаправленный revoked_at. Повторная выдача всегда с новым ID; откат/повторная публикация другого release атомарно отзывает прежние выдачи и не восстанавливает их. Авторизованные операции выдачи/отзыва с audit, блокировки org→brand→membership/release→grant; запрещать удаление и обратный переход revoked_at.

Зависимости (нужен done после merge): SP-E0-03, SP-E0-04, SP-E3-01.
Область записи: app/Seller/Knowledge/, app/Vision/KnowledgeGrants/, config/features/E8-01E.php, database/migrations/E8-01E/, docs/modules/E8-01E.md, tests/Feature/Vision/KnowledgeGrants/, tests/Unit/Vision/KnowledgeGrants/, docs/results/SP-E8-01E.md.
Экраны: инфраструктура/контракты.
Источники: E8-01.
- E8-01: 03 §2.1; eyon `Services/Visibility/*`, `Audit/*`; R-V-05

Приёмка:
- PostgreSQL-тесты выдачи, отзыва, невозможности оживить старый grant, конкуренции gate/publish/rollback, изоляции брендов, прав и audit. Без внешнего AI.

Дополнительно: Реализовать GrantAuthority из E8-01D: assertActive(TenantContext, grantId, releaseId, policyChecksum), блокировка и сверка tenant/status/revoked_at; до интеграции контракт E8-01D работает default-deny. Не включать передачу знаний и не закрывать SP-E8-01 до успешного end-to-end.
Не изменять shared contracts/locks/чужие модели за границами scope. Если требуется расширение scope — передать интегратору точное изменение, не обходить ownership. Доменным задачам использовать loader и регистрацию, созданные фундаментом; новый общий dependency добавляет интегратор отдельно.
Проверить реальные UI → validation → Action → storage → runtime → policy → audit пути; фиктивный API не считается готовым. UI на русском, три темы, состояния loading/empty/error, 1440/1024/390 и доступность. C/D остаются честными ограничениями/«Скоро». Реальные LLM/mail/Telegram/webhook вызовы в тестах запрещены без отдельного разрешенного live этапа.
Результат: commit в ветке, docs/results/SP-E8-01E.md с выполненным scope, тестами, ограничениями и screenshots; submit переводит только в review. Done ставит интегратор после merge и проверки ancestry. Не обновлять золотые эталоны ради зеленых тестов.

## Материалы

Исходники только для чтения: C:/OSPanel/home/souzpir.local/.local/references/{eyon-pilot,laravel-prototype}.
Полный индекс изображений: planning/materials-manifest.json; точный состав по docs/sources/02b_mockup_journal.md.
