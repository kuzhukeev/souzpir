import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
let server: ViteDevServer;
let url: string;
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E8-16G/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  url = new URL('/tests/Browser/E8-16G/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test('группировка, проверенные прогоны и карточка', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(url);
  await expect(page.getByRole('columnheader', { name: 'Покупка' })).toBeVisible();
  await expect(page.getByText('1/1 · непроверено: 1')).toBeVisible();
  await expect(page.getByText('0/1', { exact: true })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e8-16g/matrix.png', fullPage: true });
  await page.getByRole('button', { name: 'Мы есть · 1' }).click();
  const dialog = page.getByRole('dialog', { name: 'Ответ' });
  await expect(dialog.getByRole('link', { name: 'example.test' })).toHaveAttribute('href', 'https://example.test/info');
  await expect(dialog.getByText('Есть доставка')).toBeVisible();
  await expect(dialog.getByText('Устарело')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e8-16g/answer.png' });
  await page.keyboard.press('Escape');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.getByRole('button', { name: 'Мы есть · 1' }).click();
  await expect(page.getByRole('dialog', { name: 'Ответ' })).toBeVisible();
});
