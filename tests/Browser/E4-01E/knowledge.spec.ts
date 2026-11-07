import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

const base = 'http://127.0.0.1:5242/tests/Browser/E4-01E/fixture.html';
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } });
let vite: ViteDevServer;
test.beforeAll(async () => {
  vite = await createServer({ configFile: 'tests/Browser/E4-01E/vite.config.ts', server: { host: '127.0.0.1', port: 5242, strictPort: true } });
  await vite.listen();
});
test.afterAll(async () => { await vite?.close(); });

test('overview, incoming suggestions and version comparison', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(base);
  await expect(page.getByText('Бот использует опубликованную версию v2')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Входящие 2' })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e4-01e/overview-1440.png', fullPage: true });
  await page.getByRole('heading', { name: 'Входящие 2' }).scrollIntoViewIfNeeded();
  await expect(page.getByText('Предложенный ответ:')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Открыть диалог' }).last()).toHaveAttribute('href', '/seller/dialogs/dialog');
  await page.screenshot({ path: '.local/screenshots/sp-e4-01e/incoming-1440.png' });
  await page.getByRole('button', { name: 'Посмотреть изменения черновика' }).click();
  await expect(page.getByRole('dialog')).toContainText('3–5 дней');
  await page.screenshot({ path: '.local/screenshots/sp-e4-01e/compare-1440.png' });
});
