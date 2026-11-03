import { expect, test } from '@playwright/test';

test.skip(process.env.RUN_E107C_FIXTURE !== '1', 'Requires isolated E1-07C fixture server.');
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.E107C_CHROME ? { executablePath: process.env.E107C_CHROME } : {} });
const fixture = 'http://127.0.0.1:35985/tests/Browser/E1-07C/fixture.html';

test('plan dates and limit thresholds', async ({ page }) => {
  await page.goto(fixture + '?overview');
  await expect(page.getByText('Период:', { exact: false })).toContainText('01.10.2026');
  await expect(page.getByText('Продление: не задано')).toBeVisible();
  await expect(page.getByRole('link', { name: /Сменить тариф/ })).toContainText('Скоро');
  await expect(page.getByRole('progressbar', { name: 'Лимит 80' }).locator('div')).toHaveClass(/color-warning/);
  await expect(page.getByRole('progressbar', { name: 'Лимит 100' }).locator('div')).toHaveClass(/color-danger/);
  await expect(page.getByText(/Лимит исчерпан/)).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e1-07c/plan.png', fullPage: true });
});

test('daily chart and usage columns', async ({ page }) => {
  await page.goto(fixture);
  await expect(page.getByRole('img', { name: 'Столбцы использования по дням' })).toBeVisible();
  await expect(page.getByRole('columnheader', { name: 'Модуль' })).toBeVisible();
  await expect(page.getByRole('columnheader', { name: 'Стоимость в единицах тарифа' })).toBeVisible();
  await expect(page.getByRole('combobox', { name: 'Выбрать бренд' })).toBeEnabled();
  await page.screenshot({ path: '.local/screenshots/sp-e1-07c/usage.png', fullPage: true });
});
