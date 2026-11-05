import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E6-03X/vite.config.ts', server: { host: '127.0.0.1', port: 0 } });
  await server.listen();
  url = new URL('/tests/Browser/E6-03X/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('состояния сохранения и ошибки на полном экране', async ({ page }) => {
  await page.goto(url);
  await page.route('**/seller/settings/followups', async route => { await new Promise(resolve => setTimeout(resolve, 800)); await route.abort(); });
  await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Сохраняем…' })).toBeDisabled();
  await expect(page.getByRole('alert')).toContainText('Не удалось сохранить настройки');
  await mkdir('.local/screenshots/SP-E6-03X', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E6-03X/error.png', fullPage: true });
});
