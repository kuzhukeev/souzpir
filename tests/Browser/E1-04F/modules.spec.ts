import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E1-04F/vite.config.ts', cacheDir: '.local/runtime/vite-e104f', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E1-04F/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('shows disabled date and the 90-day review date', async ({ page }) => {
 await page.goto(url);
 await expect(page.getByText(/Отключён 15.01.2026/)).toHaveCount(2);
 await expect(page.getByText(/Проверка срока 90 дней: 15.04.2026/)).toHaveCount(2);
 await mkdir('.local/screenshots/SP-E1-04F', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E1-04F/modules.png', fullPage: true });
});
