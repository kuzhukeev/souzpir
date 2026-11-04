import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
let server: ViteDevServer;
let fixture: string;
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E5-04L/vite.config.ts', server: { host: '127.0.0.1', port: 0 } });
  await server.listen();
  fixture = new URL('/tests/Browser/E5-04L/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test('фильтры, пометки и ошибка загрузки', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(fixture);
  await expect(page.getByRole('button', { name: 'Ждут менеджера' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Закрытые' })).toBeVisible();
  await expect(page.getByLabel('Итог')).toBeVisible();
  await expect(page.getByLabel('Период')).toBeVisible();
  await expect(page.getByLabel('Только с пометками')).toBeVisible();
  await expect(page.getByText('Пометки: 2')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e5-04l/dialogs.png', fullPage: true });
  await page.route('**/seller/dialogs?**', route => route.abort());
  await page.getByRole('button', { name: 'Ждут менеджера' }).click();
  await expect(page.getByRole('alert')).toContainText('DIALOGS_LOAD');
  await page.goto(`${fixture}?error`);
  await expect(page.getByRole('alert')).toContainText('DIALOGS_LOAD');
  await expect(page.getByRole('button', { name: 'Повторить' })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e5-04l/error.png', fullPage: true });
});
