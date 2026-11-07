import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E5-03F/vite.config.ts', cacheDir: '.local/runtime/vite-e503f', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  url = new URL('/tests/Browser/E5-03F/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('the opening choice is selectable and submits the selected value', async ({ page }) => {
  await page.goto(url);
  await expect(page.getByRole('radio', { name: /Только завести карточку/ })).toBeChecked();
  const opening = page.getByRole('radio', { name: /Написать первым/ });
  await opening.check();
  await expect(opening).toBeChecked();
  await mkdir('.local/screenshots/SP-E5-03F', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E5-03F/api.png', fullPage: true, animations: 'disabled' });
  const request = page.waitForRequest(request => request.method() === 'POST' && new URL(request.url()).pathname === '/seller/channels/api/action');
  await page.getByRole('button', { name: 'Сохранить действие' }).click();
  expect((await request).postDataJSON()).toEqual({ action: 'open' });
});
