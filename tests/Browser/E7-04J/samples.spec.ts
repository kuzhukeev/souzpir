import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E7-04J/vite.config.ts', cacheDir: '.local/runtime/vite-e704j', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E7-04J/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('shows samples added by manager', async ({ page }) => {
 await page.goto(url);
 await page.getByRole('button', { name: 'Менеджеры' }).click();
 await expect(page.getByRole('columnheader', { name: 'Образцов речи добавил' })).toBeVisible();
 await expect(page.getByRole('cell', { name: '4', exact: true }).first()).toBeVisible();
 await mkdir('.local/screenshots/SP-E7-04J', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E7-04J/managers.png', fullPage: true });
});
