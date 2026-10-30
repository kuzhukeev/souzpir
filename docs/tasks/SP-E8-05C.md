# SP-E8-05C · Публичный отчёт, TTL и отзыв ссылки v1

Прочитать AGENTS.md и docs/preparation/decisions.md. Выполнять только после разрешенного старта проекта и атомарного claim. Отдельный git worktree, не основной checkout.

Цель: V-5.10/V-5.P: публикация SSR read-only отчета, безопасный token, настраиваемый срок действия и отзыв. Пользователь явно включил TTL в v1; не считать его C/v2. Публичная ссылка не раскрывает внутренние сообщения/секреты или другие бренды.

Зависимости (нужен done после merge): SP-E8-05, SP-F-01.
Область записи: app/Vision/Publications/, config/features/E8-05C.php, database/factories/Vision/Publications/, database/migrations/E8-05C/, database/seeders/Vision/Publications/, docs/modules/E8-05C.md, lang/ru/Vision/Publications/, resources/js/features/Vision/Publications/, resources/js/pages/Vision/Publications/, resources/js/types/Vision/Publications/, resources/views/public-report/, routes/features/E8-05C.php, tests/Browser/E8-05C/, tests/Feature/Vision/Publications/, tests/Unit/Vision/Publications/, docs/results/SP-E8-05C.md.
Экраны: V-5.10.
Источники: E8-05.
- E8-05: ТЗ V-5.1–V-5.10, V-6.1–V-6.4, V-7.1–V-7.3; макеты 105–114; R-V-09, R-V-10

Приёмка:
- Time travel: до/после expires_at и revoke; 410 после срока/отзыва; noindex/cache policy; desktop/mobile visual.

Дополнительно: Сохранять поведение донора; изменения только по принятым решениям.
Не изменять shared contracts/locks/чужие модели за границами scope. Если требуется расширение scope — передать интегратору точное изменение, не обходить ownership. Доменным задачам использовать loader и регистрацию, созданные фундаментом; новый общий dependency добавляет интегратор отдельно.
Проверить реальные UI → validation → Action → storage → runtime → policy → audit пути; фиктивный API не считается готовым. UI на русском, три темы, состояния loading/empty/error, 1440/1024/390 и доступность. C/D остаются честными ограничениями/«Скоро». Реальные LLM/mail/Telegram/webhook вызовы в тестах запрещены без отдельного разрешенного live этапа.
Результат: commit в ветке, docs/results/SP-E8-05C.md с выполненным scope, тестами, ограничениями и screenshots; submit переводит только в review. Done ставит интегратор после merge и проверки ancestry. Не обновлять золотые эталоны ради зеленых тестов.

## Материалы

Исходники только для чтения: C:/OSPanel/home/souzpir.local/.local/references/{eyon-pilot,laravel-prototype}.
Полный индекс изображений: planning/materials-manifest.json; точный состав по docs/sources/02b_mockup_journal.md.

- `C:\OSPanel\home\tasks.local\completed\106\0219e351-V-5.10-public.png`
- `C:\OSPanel\home\tasks.local\completed\106\2f2075a9-V-5.10-public-detail.png`
- `C:\OSPanel\home\tasks.local\completed\106\f82e7785-V-5.10-share.png`
- `C:\OSPanel\home\tasks.local\completed\107\3ca56229-V-5.P-desktop-bottom.png`
- `C:\OSPanel\home\tasks.local\completed\107\42b3951e-V-5.P-mobile-bottom.png`
- `C:\OSPanel\home\tasks.local\completed\107\747c7dba-V-5.P-mobile-top.png`
- `C:\OSPanel\home\tasks.local\completed\107\b321ea60-V-5.P-desktop-top.png`
