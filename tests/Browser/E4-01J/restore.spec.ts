import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let fixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E4-01J/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  fixture = new URL('/tests/Browser/E4-01J/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

test('автоматически сохранённый черновик доступен для восстановления', async ({ page }) => {
  await page.goto(fixture);
  await expect(page.getByRole('heading', { name: 'Сохранённые черновики' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Восстановить', exact: true })).toBeEnabled();
  await expect(page.getByRole('button', { name: 'Создать черновик' }).last()).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Откатить с сохранением' })).toBeEnabled();
  await expect(page.getByText(/критерии без надёжной проверки/)).toBeVisible();
  await page.getByRole('heading', { name: 'Сохранённые черновики' }).scrollIntoViewIfNeeded();
  await page.screenshot({ path: '.local/screenshots/SP-E4-01J/restore.png' });
});

test('локальный прогон показывает три хода диалога', async ({ page }) => {
  await page.goto(new URL('/tests/Browser/E4-01J/run.html', fixture).href);
  await expect(page.getByText('Клиент: Уточню: Оплата')).toBeVisible();
  await expect(page.getByText('Продавец: Оплата по согласованию.')).toBeVisible();
  await expect(page.getByText('Не больше одного вопроса за ход: пройдено')).toHaveCount(3);
  await page.screenshot({ path: '.local/screenshots/SP-E4-01J/run.png' });
});
