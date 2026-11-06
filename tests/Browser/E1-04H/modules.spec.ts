import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E1-04H/vite.config.ts', server: { host: '127.0.0.1', port: 0 } });
  await server.listen();
  url = new URL('/tests/Browser/E1-04H/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('вкладки и подключение модуля', async ({ page }) => {
  await page.goto(url);
  await expect(page.getByRole('link', { name: 'Домены' })).toHaveAttribute('href', '/brands/b/settings?tab=domains');
  await expect(page.locator('nav[aria-label="Настройки бренда"] [aria-current="page"]')).toHaveText('Модули');
  await expect(page.getByText('Настройте вопросы о бренде.')).toBeVisible();
  await mkdir('.local/screenshots/SP-E1-04H', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E1-04H/modules.png', fullPage: true });
  let payload: unknown;
  await page.route('**/brands/b/modules/seller', async route => { payload = route.request().postDataJSON(); await route.abort(); });
  await page.getByRole('button', { name: 'Подключить ИИ-продавец' }).click();
  await expect.poll(() => payload).toEqual({ enabled: true });
  await expect(page.getByRole('alert')).toContainText('Не удалось связаться');
});
