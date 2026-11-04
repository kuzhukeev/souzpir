import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
let server: ViteDevServer;
let fixture: string;
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E8-19B/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  fixture = new URL('/tests/Browser/E8-19B/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test('критерии, шаги, изменения и публикация', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(fixture);
  await expect(page.getByText('Правила доступа роботов · Есть')).toBeVisible();
  await expect(page.getByText('Шаг три')).toBeVisible();
  await expect(page.getByText('Появились в ответах:', { exact: false })).toBeVisible();
  await page.locator('#site').scrollIntoViewIfNeeded();
  await page.screenshot({ path: '.local/screenshots/sp-e8-19b/report.png' });
  await page.getByRole('button', { name: 'Поделиться отчётом' }).click();
  await expect(page.getByRole('switch', { name: 'Публичная ссылка' })).toBeChecked();
  await expect(page.getByText('Запрос отзыва и просмотры будут доступны позже.')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e8-19b/share.png' });
  await page.getByRole('button', { name: 'Готово' }).click();
});
