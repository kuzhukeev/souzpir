import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let fixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E8-16D/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  fixture = new URL('/tests/Browser/E8-16B/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

test('ход замера показывает этапы вопросов и статус модели', async ({ page }) => {
  await page.goto(fixture);
  await expect(page.getByText('2 из 2 ответов получено')).toBeVisible();
  await expect(page.getByText('gemini: Ошибка · 2 из 2')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E8-16D/progress.png', fullPage: true });
});

test('ожидающий замер предлагает уведомление', async ({ page }) => {
  await page.goto(`${fixture}?pending`);
  await expect(page.getByRole('link', { name: 'Уведомить, когда будет готово' })).toHaveAttribute('href', '/vision/settings');
  await expect(page.getByText('gemini: Ожидает · 0 из 1')).toBeVisible();
});
