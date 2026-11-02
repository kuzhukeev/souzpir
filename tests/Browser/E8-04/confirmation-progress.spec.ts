import { expect, test } from '@playwright/test';

test.use({ launchOptions: process.env.E804_CHROMIUM_PATH ? { executablePath: process.env.E804_CHROMIUM_PATH } : {} });

test('незавершённый снимок не показан подтверждённым', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('http://127.0.0.1:5239/tests/Browser/E8-04/launch-fixture.html');
  const progress = page.getByRole('region', { name: 'Подтверждение снимка' });
  await expect(progress.getByText('Получено 1 из 2')).toBeVisible();
  await expect(progress.getByText('Пока недоступно')).toBeVisible();
  await expect(progress.getByText(/Снимок не подтверждён/)).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e8-04c/progress-1440.png', fullPage: true });
});

