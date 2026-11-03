import { expect, test } from '@playwright/test';

const base = 'http://127.0.0.1:5241/tests/Browser/E5-05F/fixture.html';
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } });

test('customer list, card, and task screens show the missing fields', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${base}?screen=index`);
  await expect(page.getByText('Фейерверк')).toBeVisible();
  await expect(page.getByRole('combobox', { name: 'Размер страницы' })).toHaveValue('20');
  await page.screenshot({ path: '.local/screenshots/sp-e5-05f/customers-1440.png', fullPage: true });

  await page.goto(`${base}?screen=show`);
  await expect(page.getByText('Фейерверк · Тверь')).toBeVisible();
  await expect(page.getByText('12345')).toBeVisible();
  await expect(page.getByText('Источник: Клиент')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e5-05f/customer-1440.png', fullPage: true });

  await page.goto(`${base}?screen=tasks`);
  await expect(page.getByText('Клиент: Анна Смирнова')).toBeVisible();
  await page.getByRole('button', { name: 'Канбан' }).click();
  await page.screenshot({ path: '.local/screenshots/sp-e5-05f/tasks-1440.png', fullPage: true });
});
