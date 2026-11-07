import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E7-06E/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E7-06E/dashboard.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('сводка показывает оценку качества и фильтр канала', async ({ page }) => {
  await page.goto(url);
  const metrics = page.getByRole('region', { name: 'Показатели' });
  await expect(metrics.getByRole('heading', { name: 'Качество' })).toBeVisible();
  await expect(metrics.getByText('4,2 / 5')).toBeVisible();
  await expect(metrics.getByText('Изменение оценки пока недоступно')).toBeVisible();
  await expect(page.getByLabel('Канал')).toBeVisible();
  await mkdir('.local/screenshots/SP-E7-06E', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E7-06E/summary.png', fullPage: true });
});
