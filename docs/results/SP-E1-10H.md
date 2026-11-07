# SP-E1-10H · Интеграции: обзор — остаток 07.11.2026

Сделан только пункт из «Остаток 07.11.2026»: CRM-вебхук переведён из отдельной ссылки под сеткой в обычную плитку общей сетки «Подключения» (как Telegram, Виджет и т.д.) с «Подключён <дата>»/«Настроить».

Что сделано:
- `IntegrationsController::index` — вместо `crmHref` добавлена плитка `crm_webhook` в массив `$connections`: статус `connected`/`disconnected`/`disabled` по наличию вебхука с CRM-событиями (`seller.contact.captured`, `seller.conversation.handed_off`) у текущего бренда, `connected_at` = дата создания найденного вебхука, `href` на `seller.integrations.crm` при праве `seller.settings.read` и включённом модуле продавца (как было).
- `Index.tsx` — убран отдельный блок-ссылка «CRM-вебхук» под сеткой, проп `crmHref` удалён, иконка `crm_webhook` → `Cable` (как фолбэк для остальных плиток).
- Проверено: проверено по макету `S-5.1-connections-more.webp` (26) — плитка CRM-вебхук в общей сетке рядом с «Почта для прайса»/amoCRM/Bitrix24, с датой подключения и кнопкой «Настроить».

Тесты:
- Новый `tests/Feature/Shell/Integrations/CrmWebhookTileE110HTest.php` — плитка в сетке, изоляция по бренду, статус/дата при наличии и отсутствии вебхука, доступ owner/viewer.
- Обновлены `PriceMailOverviewE110HTest` (индекс 9 вместо `crmHref`), фикстура и `integrations.spec.ts` браузерного теста E1-10H (плитка вместо ссылки, screenshot).
- `php artisan test tests/Feature/Shell/Integrations/` (кроме уже красных вне scope — см. Техдолг) — зелёные; `npx playwright test tests/Browser/E1-10H/integrations.spec.ts` — зелёный; `check lint`, `check frontend` — зелёные.

Остаток: нет (остальные пункты карточки SP-E1-10H ранее слиты в e54de4bf).

## Техдолг
- В этом слоте `SecretSecurityTest`, `PruneSecretReceiptsTest`, `SecretSharedSessionRaceTest`, `Webhooks/WebhookSecret*` падают с «A dedicated, nonprivileged test database owner is required» — инфраструктурный тест изоляции БД в exec-6, не связано с этой задачей, не трогал.
