import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-20Q/vite.config.ts', cacheDir: '.local/runtime/vite-e820q', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E8-20Q/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('sources display description without invented difficulty', async ({ page }) => {
 await page.goto(url);
 await expect(page.getByText('Каталог поставщиков')).toBeVisible();
 await expect(page.getByText('сложность: Нет данных')).toHaveCount(0);
 await mkdir('.local/screenshots/SP-E8-20Q', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E8-20Q/sources.png', fullPage: true });
});
test('empty state links to measurements', async ({ page }) => {
 await page.goto(url + '?empty');
 await expect(page.getByText('Источники появятся после первого подтверждённого замера.')).toBeVisible();
 await expect(page.getByRole('link', { name: 'К замерам →' })).toHaveAttribute('href', '/vision/runs');
});
