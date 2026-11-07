import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E6-10E/vite.config.ts', cacheDir: '.local/runtime/vite-e610e', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E6-10E/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('schema dialog shows published additional fields together with system fields', async ({ page }) => {
 await page.goto(url);
 await page.getByRole('button', { name: 'Схема полей' }).click();
 const dialog = page.getByRole('dialog');
 await expect(dialog.getByText('Город')).toBeVisible();
 await expect(dialog.getByText('Марка и модель')).toBeVisible();
 await mkdir('.local/screenshots/SP-E6-10E', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E6-10E/fields-schema.png', fullPage: true });
});
