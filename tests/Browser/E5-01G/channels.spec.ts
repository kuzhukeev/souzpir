import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E5-01G/vite.config.ts', cacheDir: '.local/runtime/vite-e501g', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E5-01G/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('channels and API log show actual data', async ({ page }) => {
 await page.goto(url);
 await expect(page.getByText('Адреса сайта: https://example.test')).toBeVisible();
 await expect(page.getByText('Подключён', {exact: true})).toBeVisible();
 await expect(page.getByRole('button', {name: 'Остановить всех ботов'})).toHaveClass(/color-danger/);
 await mkdir('.local/screenshots/SP-E5-01G', {recursive:true});
 await page.screenshot({path:'.local/screenshots/SP-E5-01G/channels.png',fullPage:true});
 await page.goto(url+'?settings');
 const logRow = page.getByRole('row').filter({ hasText: 'API · /api/v1/leads' });
 await expect(logRow).toBeVisible();
 await expect(logRow).toContainText('Успешно');
});
