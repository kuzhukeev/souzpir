import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E7-04L/vite.config.ts', cacheDir: '.local/runtime/vite-e704l', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E7-04L/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('explains excluded historical samples', async ({ page }) => {
 await page.goto(url);
 await page.getByRole('button', { name: 'Менеджеры' }).click();
 await expect(page.getByText(/Старые импорты и предложения без указанного типа не учитываются/)).toBeVisible();
 await mkdir('.local/screenshots/SP-E7-04L', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E7-04L/managers.png', fullPage: true });
});
