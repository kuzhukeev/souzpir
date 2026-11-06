import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E6-03Y/vite.config.ts', server: { host: '127.0.0.1', port: 0 } });
  await server.listen();
  url = new URL('/tests/Browser/E6-03Y/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('ошибка загрузки не выглядит как отсутствие публикации', async ({ page }) => {
  await page.goto(url);
  await expect(page.getByRole('alert')).toContainText('Не удалось загрузить воронку');
  await expect(page.getByText('Нет опубликованной базы знаний.')).toHaveCount(0);
  await mkdir('.local/screenshots/SP-E6-03Y', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E6-03Y/error.png', fullPage: true });
});
