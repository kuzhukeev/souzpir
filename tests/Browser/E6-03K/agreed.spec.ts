import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E6-03K/vite.config.ts', cacheDir: '.local/runtime/vite-e603k', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E6-03K/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('согласованное время видно, неподтверждённые рассылки отключены', async ({ page }) => {
    await page.goto(url);
    await expect(page.getByRole('row').filter({ hasText: 'Анна' }).getByText(/согласовано/)).toBeVisible();
    await expect(page.getByText(/Массовая отправка недоступна/)).toBeVisible();
    await expect(page.getByRole('button', { name: /Отправить всем/ })).toHaveCount(0);
    await mkdir('.local/screenshots/SP-E6-03K', { recursive: true });
    await page.screenshot({ path: '.local/screenshots/SP-E6-03K/followups.png', fullPage: true });
});
