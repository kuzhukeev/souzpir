import { expect, test } from '@playwright/test';

test.use({
  launchOptions: process.env.CRM_CHROMIUM_PATH
    ? { executablePath: process.env.CRM_CHROMIUM_PATH }
    : {},
});

test('страница честно показывает недоступную доставку', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('http://127.0.0.1:5269/tests/Browser/E6-09/ui-fixture.html');
  await expect(page.getByRole('heading', { name: 'CRM-вебхук' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Настройка вебхука' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Открыть настройки вебхуков' })).toBeVisible();
  await expect(page.getByText('Контакт получен').first()).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/e6-09/crm-1440.png', fullPage: true });
});
