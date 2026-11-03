import { expect, test } from '@playwright/test';

const url = 'http://127.0.0.1:5247/tests/Browser/E6-07B/fixture.html';
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH }, viewport: { width: 1440, height: 900 } });

test('period, consequences and tariff link are visible', async ({ page }) => {
  await page.goto(url);
  await expect(page.getByRole('heading', { name: 'До конца периода' })).toBeVisible();
  await expect(page.getByText('12 дн.', { exact: false })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Что произойдёт при исчерпании' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Открыть тариф и лимиты' })).toHaveAttribute('href', '/billing/plans');
  await expect(page.getByRole('progressbar', { name: 'Диалоги за месяц' })).toHaveAttribute('aria-valuenow', '80');
  await page.screenshot({ path: '.local/screenshots/sp-e6-07b/limits-1440.png', fullPage: true });
});

test('empty usage and restricted tariff link are clear', async ({ page }) => {
  await page.goto(`${url}?empty&restricted`);
  await expect(page.getByText('Данные об использовании пока недоступны.')).toBeVisible();
  await expect(page.getByText('Тариф доступен владельцу компании.')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Открыть тариф и лимиты' })).toHaveCount(0);
});
