import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E1-04E/vite.config.ts', cacheDir: '.local/runtime/vite-e104e', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E1-04E/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('module counts and retention message', async ({ page }) => {
 await page.goto(url);
 await expect(page.getByText('Активные каналы: 2')).toBeVisible();
 await expect(page.getByText('Действующие публичные ссылки: 3')).toBeVisible();
 await expect(page.getByRole('link', { name: 'Настройки бренда' })).toHaveAttribute('href', '/brands/b/settings');
 await mkdir('.local/screenshots/SP-E1-04E', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E1-04E/modules.png', fullPage: true });
 await page.getByRole('switch', { name: 'Отключить Видение' }).click();
 await expect(page.getByText(/Сохранённые данные останутся не менее 90 дней после отключения/)).toBeVisible();
});
