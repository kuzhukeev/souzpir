import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E5-03D/vite.config.ts', cacheDir: '.local/runtime/vite-e503d', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen(); url = new URL('/tests/Browser/E5-03D/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('addresses, honest lead state and recent API results', async ({ page, context }) => {
  await context.grantPermissions(['clipboard-read', 'clipboard-write']);
  await page.goto(url);
  await expect(page.getByRole('heading', { name: 'Входящие формы и API' })).toBeVisible();
  await page.getByRole('button', { name: 'Копировать /api/v1/turn' }).click();
  await expect(page.getByRole('status')).toContainText('Скопировано');
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(new URL('/api/v1/turn', url).href);
  await expect(page.getByRole('radio', { name: /Написать первым/ })).toBeEnabled();
  await expect(page.getByRole('radio', { name: /Только завести карточку/ })).toBeChecked();
  for (const name of ['Время', 'Источник', 'Статус', 'Ошибка']) await expect(page.getByRole('columnheader', { name })).toBeVisible();
  await expect(page.getByRole('row').filter({ hasText: 'HTTP 422' })).toContainText('/api/v1/turn');
  await expect(page.getByRole('link', { name: 'Журнал ошибок API →' })).toHaveAttribute('href', '/seller/channels/api/failures');
  await mkdir('.local/screenshots/SP-E5-03D', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E5-03D/api.png', fullPage: true, animations: 'disabled' });
  for (const width of [1024, 390]) { await page.setViewportSize({ width, height: 900 }); await expect(page.getByRole('heading', { name: 'Входящие формы и API' })).toBeVisible(); }
});
