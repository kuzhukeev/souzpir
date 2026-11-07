import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined } });
let server: ViteDevServer;
let url: string;
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E6-08E/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  url = new URL('/tests/Browser/E6-08E/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('пример использует результат проверки остатков', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${url}?stock`);
  await expect(page.getByText('«Товар: в наличии 4.»')).toBeVisible();
  await expect(page.getByRole('button', { name: '1С · Скоро' })).toBeDisabled();
  await page.screenshot({ path: '.local/runtime/e6-08e-stock.png', fullPage: true });
  await page.goto(url);
  await expect(page.getByRole('button', { name: 'Google Sheets · Скоро' })).toBeDisabled();
  await page.screenshot({ path: '.local/runtime/e6-08e-catalog.png', fullPage: true });
});
