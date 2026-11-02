import { expect, test } from '@playwright/test';

test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });

test('выбор релизов и сравнение на светлой теме', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  const fixture = 'http://127.0.0.1:5274/tests/Browser/E4-01/compare-fixture.html';
  await page.goto(fixture);
  await expect(page.getByRole('heading', { name: 'Сравнение релизов' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Сравнить' })).toBeEnabled();
  await page.screenshot({ path: '.local/screenshots/sp-e4-01b/releases-1440.png', fullPage: true });
  await page.goto(`${fixture}?compare`);
  await expect(page.getByText('Изменений: 2')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Факты' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Условия' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Темы' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Образцы' })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.screenshot({ path: '.local/screenshots/sp-e4-01b/compare-1440.png', fullPage: true });
});
