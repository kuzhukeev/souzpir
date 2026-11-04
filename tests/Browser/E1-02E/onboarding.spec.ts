import { test, expect } from '@playwright/test';
import { mkdir } from 'node:fs/promises';

test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });

test('проверка сайта и выбор SVG в мастере', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('http://127.0.0.1:5402/tests/Browser/E1-02E/fixture.html');
  await expect(page.getByRole('heading', { name: 'Данные бренда' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Проверить сайт' })).toBeVisible();
  await expect(page.locator('input[type=file]')).toHaveAttribute('accept', /image\/svg\+xml/);
  await expect(page.getByText('Сайт доступен')).toBeVisible();
  await mkdir('.local/screenshots/SP-E1-02E', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E1-02E/brand.png', fullPage: true });
  await page.getByLabel('Адрес сайта').fill('https://changed.example.com');
  await expect(page.getByText('Сайт доступен')).toHaveCount(0);
});
