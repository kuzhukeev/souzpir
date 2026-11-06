import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E0-05G/reconcile-vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E0-05G/reconcile-fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('operator sees verified cost while ordinary owner sees none', async ({ page }) => {
  await page.route('**/ai/recovery/calls**', route => route.fulfill({ json: { calls: [], nextCursor: null } }));
  await page.goto(url);
  await expect(page.getByText('Подтверждённая стоимость сверки')).toHaveCount(0);
  await page.goto(url + '?operator');
  await expect(page.getByText('0.000150 USD')).toBeVisible();
  await mkdir('.local/screenshots/SP-E0-05G', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E0-05G/recovery.png', fullPage: true });
  await page.goto(url + '?operator&empty');
  await expect(page.getByText('Подтверждённых сумм пока нет.')).toBeVisible();
});
