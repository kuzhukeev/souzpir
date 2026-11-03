import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

test.setTimeout(60000);
let server: ViteDevServer;
let url: string;
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E8-22/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  url = new URL('/tests/Browser/E8-22/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

test('текущие действия и форма завершения', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(url);
  await expect(page.getByText('Добавить раздел с условиями')).toBeVisible();
  await page.getByRole('button', { name: 'Отметить выполненным' }).click();
  await expect(page.getByText('Дата выполнения')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E8-22/current.png', fullPage: true });
});

test('история сохраняет осторожную оценку эффекта', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${url}?history`);
  await expect(page.getByText(/Рано судить: порядок/)).toBeVisible();
  await expect(page.getByRole('link', { name: 'Посмотреть эффект' })).toHaveAttribute('href', '/vision/actions/effect');
  await page.screenshot({ path: '.local/screenshots/SP-E8-22/history.png', fullPage: true });
});

test('ошибка сохранения предлагает повторить', async ({ page }) => {
  await page.goto(url);
  await page.route('**/vision/actions/a', route => route.abort());
  await page.getByRole('button', { name: 'Отметить выполненным' }).click();
  await page.getByLabel('Дата выполнения').fill('2026-11-01');
  await page.getByLabel('Что сделано').fill('Добавлен раздел');
  await page.locator('form').last().getByRole('button', { name: 'Отметить выполненным' }).click();
  await expect(page.getByRole('alert')).toContainText('Не удалось сохранить действие.');
  await expect(page.getByRole('button', { name: 'Повторить' })).toBeVisible();
});
