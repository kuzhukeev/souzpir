import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined } });
let server: ViteDevServer;
let url: string;
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E6-08F/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  url = new URL('/tests/Browser/E6-08F/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('каталог показывает настройки бренда и источники', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(url);
  await expect(page.getByLabel('Бот может называть цены')).toBeChecked();
  await expect(page.getByLabel('Бот может называть скидки бренда')).not.toBeChecked();
  await expect(page.getByRole('button', { name: 'Google Sheets · Скоро' })).toBeDisabled();
  await page.screenshot({ path: '.local/runtime/e6-08f-catalog.png', fullPage: true });
});

test('ошибка каталога позволяет повторить загрузку', async ({ page }) => {
  await page.goto(`${url}?error`);
  await expect(page.getByRole('alert')).toContainText('Не удалось загрузить товары.');
  await expect(page.getByRole('button', { name: 'Повторить' })).toBeVisible();
});
