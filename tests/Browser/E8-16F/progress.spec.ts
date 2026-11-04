import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let fixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E8-16D/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  fixture = new URL('/tests/Browser/E8-16F/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

test('ход замера показывает этапы вопросов и статус модели', async ({ page }) => {
  await page.goto(fixture);
  await expect(page.getByText('2 из 2 ответов получено')).toBeVisible();
  await expect(page.getByText('gemini: Ошибка · 2 из 2')).toBeVisible();
  await expect(page.getByText('Модели: google/gemini-2.5-flash')).toBeVisible();
  await expect(page.getByText('48 %')).toBeVisible();
  await expect(page.getByText('-2 п.п.')).toBeVisible();
  await page.getByText('Действия', { exact: true }).last().click();
  await expect(page.getByRole('link', { name: 'Ответы', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Повторить замер' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'События замера' })).toBeVisible();
  await expect(page.locator('time[datetime="2026-10-20T12:30:00Z"]')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E8-16F/progress.png', fullPage: true });
});

test('ожидающий замер предлагает уведомление', async ({ page }) => {
  await page.goto(`${fixture}?pending`);
  await expect(page.getByRole('link', { name: 'Уведомить, когда будет готово' })).toHaveAttribute('href', '/vision/settings');
  await expect(page.getByText('gemini: Ожидает · 0 из 1')).toBeVisible();
});
