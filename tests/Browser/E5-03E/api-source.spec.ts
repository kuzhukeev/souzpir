import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E5-03E/vite.config.ts', cacheDir: '.local/runtime/vite-e503e', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  url = new URL('/tests/Browser/E5-03E/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('shows explicit source for successful and failed requests', async ({ page }) => {
  await page.goto(url);
  await expect(page.getByRole('row').filter({ hasText: 'Успешно' })).toContainText('Сайт');
  await expect(page.getByRole('row').filter({ hasText: 'HTTP 422' })).toContainText('CRM');
  await expect(page.getByText('source: site или crm', { exact: false })).toBeVisible();
  await mkdir('.local/screenshots/SP-E5-03E', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E5-03E/api.png', fullPage: true, animations: 'disabled' });
});
