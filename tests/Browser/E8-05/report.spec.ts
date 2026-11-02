import { expect, test } from '@playwright/test';

test.use({ launchOptions: process.env.E805_CHROMIUM_PATH ? { executablePath: process.env.E805_CHROMIUM_PATH } : {} });

test('внутренний отчёт и пустое состояние', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  const origin = process.env.E805_FIXTURE_ORIGIN ?? 'http://127.0.0.1:5240';
  await page.goto(`${origin}/tests/Browser/E8-05/report-fixture.html`);
  await expect(page.getByRole('heading', { name: 'Внутренний отчёт' })).toBeVisible();
  await expect(page.getByText('Видимость по моделям')).toBeVisible();
  await expect(page.getByText('Недостаточно данных').first()).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e8-05/report-1440.png', fullPage: true });
  await page.goto(`${origin}/tests/Browser/E8-05/report-fixture.html?empty`);
  await expect(page.getByText('Пока нет подтверждённого замера.')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e8-05/empty-1440.png', fullPage: true });
});
