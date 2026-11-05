import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-01Y/vite.config.ts', cacheDir: '.local/runtime/vite-e401y', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E4-01Y/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('history shows changes against previous publication', async ({ page }) => {
 await page.goto(url);
 await expect(page.getByRole('row').filter({ hasText: 'v2' }).getByRole('cell', { name: '4', exact: true })).toBeVisible();
 await expect(page.getByText(/Изменения рассчитаны по сравнению с предыдущей публикацией/)).toBeVisible();
 await mkdir('.local/screenshots/SP-E4-01Y', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E4-01Y/history.png', fullPage: true });
});
