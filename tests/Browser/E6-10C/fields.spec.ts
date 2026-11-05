import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E6-10C/vite.config.ts', cacheDir: '.local/runtime/vite-e610c', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E6-10C/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('published custom fields show their requirement and stage', async ({ page }) => {
 await page.goto(url);
 const row = page.getByRole('row').filter({ hasText: 'Город клиента' });
 await expect(row.getByText('Да', { exact: true })).toBeVisible();
 await expect(row.getByText('Передача менеджеру')).toBeVisible();
 await mkdir('.local/screenshots/SP-E6-10C', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E6-10C/fields.png', fullPage: true });
});
