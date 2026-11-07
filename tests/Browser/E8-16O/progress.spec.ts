import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, colorScheme: 'light', launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-16O/vite.config.ts', cacheDir: '.local/runtime/vite-e816o', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E8-16O/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('успешные ответы, ошибки и неизвестные исходы считаются отдельно', async ({ page }) => {
 await page.goto(url);
 await expect(page.getByText('3 из 4 слотов обработано', { exact: false })).toBeVisible();
 await expect(page.getByText('Получено ответов: 1 · Ошибок: 1 · Требуют проверки: 1')).toBeVisible();
 await expect(page.getByText('Вопрос 4 · fake · Ожидается')).toBeVisible();
 await mkdir('.local/screenshots/SP-E8-16O', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E8-16O/progress.png', fullPage: true });
});
