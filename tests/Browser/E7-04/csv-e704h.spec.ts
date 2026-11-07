import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E7-04/vite.config.ts', server: { host: '127.0.0.1', port: 0 } });
  await server.listen();
  url = new URL('/tests/Browser/E7-04/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('CSV сохраняет фильтры отчёта', async ({ page }) => {
  await page.goto(url);
  const href = await page.getByRole('link', { name: 'Скачать CSV' }).getAttribute('href');
  expect(href).toContain('from=2026-11-01');
  expect(href).toContain('to=2026-11-03');
  await mkdir('.local/screenshots/SP-E7-04H', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E7-04H/analytics.png', fullPage: true });
});
