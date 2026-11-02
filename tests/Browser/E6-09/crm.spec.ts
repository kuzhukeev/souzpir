import { expect, test } from '@playwright/test';

test.use({
  launchOptions: process.env.CRM_CHROMIUM_PATH
    ? { executablePath: process.env.CRM_CHROMIUM_PATH }
    : {},
});

test('страница показывает тестовую доставку и результат в журнале', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('http://127.0.0.1:5269/tests/Browser/E6-09/ui-fixture.html');
  await expect(page.getByRole('heading', { name: 'CRM-вебхук' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Настройка вебхука' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Открыть настройки вебхуков' })).toBeVisible();
  await expect(page.getByText('Контакт получен').first()).toBeVisible();
  await expect(page.getByRole('button', { name: 'Отправить тестовое событие' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Проверить ошибку' })).toBeVisible();
  await expect(page.getByText('Ошибка (503)')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e6-09c/crm-1440.png', fullPage: true });
});
