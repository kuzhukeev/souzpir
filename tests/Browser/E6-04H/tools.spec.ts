import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, colorScheme: 'light', launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E6-04H/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E6-04H/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('счётчик и отсутствие данных', async ({ page }) => {
 await page.goto(url);
 await expect(page.getByText('Вызовы за 30 дней: 3')).toBeVisible();
 await expect(page.getByText('Вызовы за 30 дней: нет данных')).toHaveCount(3);
 await mkdir('.local/screenshots/SP-E6-04H', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E6-04H/tools.png', fullPage: true });
});
