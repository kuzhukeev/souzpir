import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let fixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E8-24D/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  fixture = new URL('/tests/Browser/E8-24D/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

test('доступные и будущие модели, получатели и слоты', async ({ page }) => {
  await page.goto(fixture);
  await expect(page.getByText('Скоро — модель ещё не подключена.')).toBeVisible();
  await expect(page.getByRole('checkbox', { name: /GigaChat/ })).toBeDisabled();
  await expect(page.getByRole('checkbox', { name: /Gemini/ })).toBeChecked();
  await page.screenshot({ path: '.local/screenshots/SP-E8-24D/models.png', fullPage: true });
  await page.getByRole('button', { name: 'Получатели' }).click();
  await expect(page.getByText('По праву просмотра замеров')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E8-24D/recipients.png', fullPage: true });
  await page.getByRole('button', { name: 'Слоты' }).click();
  await expect(page.getByText(/Расход и остаток слотов не предоставлены источником/)).toBeVisible();
});

test('одобренные модели остаются доступными без подробного каталога', async ({ page }) => {
  await page.goto(fixture + '?legacy=1');
  await expect(page.getByText('Выбрано 1 из 2')).toBeVisible();
  await page.getByRole('checkbox', { name: /claude-sonnet/ }).check();
  await expect(page.getByText('Выбрано 2 из 2')).toBeVisible();
});
