import { expect, test } from '@playwright/test';

const base = 'http://127.0.0.1:5239/tests/Browser/E1-10G/fixture.html';
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } });

test('API screen links to documentation and keeps delivery honest', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${base}?screen=api`);
  await expect(page.getByRole('link', { name: 'Документация API' })).toHaveAttribute('href', '/integrations/api/docs');
  await expect(page.getByText('Реальная доставка вебхуков выключена.', { exact: false })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e1-10g/api-1440.png', fullPage: true });
  await page.goto(`${base}?screen=docs`);
  await expect(page.getByRole('heading', { name: 'Документация API' })).toBeVisible();
  await expect(page.getByRole('cell', { name: '/api/v1/brand' })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e1-10g/docs-1440.png', fullPage: true });
});

test('company notification matrix and personal link', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${base}?screen=notifications`);
  await expect(page.getByRole('heading', { name: 'Общие уведомления' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Личные настройки уведомлений' })).toHaveAttribute('href', '/profile/notifications');
  const toggle = page.getByRole('checkbox', { name: 'Диалог ждёт менеджера: Почта' });
  await expect(toggle).not.toBeChecked();
  await toggle.check();
  await expect(toggle).toBeChecked();
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: '.local/screenshots/sp-e1-10g/notifications-1440.png', fullPage: true });
});
