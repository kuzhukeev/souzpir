import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
let server: ViteDevServer;
let fixture: string;
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E4-01I/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  fixture = new URL('/tests/Browser/E4-01I/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test('локальная проверка и сохранность черновика', async ({ page }) => {
  await page.goto(fixture);
  await expect(page.getByText('Локальная проверка одного хода использует', { exact: false })).toBeVisible();
  await expect(page.getByText('полноценный прогон ещё не подключён.', { exact: false })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Скачать копию черновика' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Создать черновик' })).toBeDisabled();
  await page.screenshot({ path: '.local/screenshots/sp-e4-01i/releases.png', fullPage: true });
});
