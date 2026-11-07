import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E8-14K/vite.config.ts', server: { host: '127.0.0.1', port: 0 } });
  await server.listen();
  url = new URL('/tests/Browser/E8-14K/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('отображается загруженный логотип бренда', async ({ page }) => {
  await page.route('**/brands/b/settings/logo', route => route.fulfill({ status: 200, contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48"><circle cx="24" cy="24" r="20" fill="green"/></svg>' }));
  await page.goto(url);
  await expect(page.getByRole('img', { name: 'Логотип бренда Бренд' })).toBeVisible();
  await mkdir('.local/screenshots/SP-E8-14K', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E8-14K/start.png', fullPage: true });
});
