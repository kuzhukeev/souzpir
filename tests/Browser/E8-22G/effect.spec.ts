import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, colorScheme: 'light', launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E8-22G/vite.config.ts', cacheDir: '.local/runtime/vite-e822g-effect', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  url = new URL('/tests/Browser/E8-22G/effect-fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => {
  await server?.close();
});
test('история действий показывает таблицу с замерами, видимостью и эффектом', async ({ page }) => {
  await page.goto(url);
  await expect(page.getByRole('heading', { name: 'История и эффект' })).toBeVisible();
  await expect(page.getByText('Достижений пока нет')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'История действий и их эффект' })).toBeVisible();
  const row = page.getByRole('row', { name: /Обновить описание доставки/ });
  await expect(row.getByText('№3 → №4')).toBeVisible();
  await expect(row.getByText('31% → 40%')).toBeVisible();
  await expect(row.getByText('Есть рост')).toBeVisible();
  await expect(page.getByRole('row', { name: /Уточнить карточку компании/ }).getByText('Без изменений')).toBeVisible();
  const open = page.getByRole('row', { name: /Обновить страницу каталога/ });
  await expect(open.getByText('№4 → —')).toBeVisible();
  await expect(open.getByText('Рано судить')).toBeVisible();
  await mkdir('.local/screenshots/SP-E8-22G', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E8-22G/effect.png', fullPage: true });
});
test('пустое состояние показывает кнопку «К действиям»', async ({ page }) => {
  await page.goto(url + '?empty');
  await expect(page.getByText('Достижений пока нет')).toBeVisible();
  await expect(page.getByText('Выполненных действий пока нет')).toBeVisible();
  await expect(page.getByRole('link', { name: 'К действиям' })).toHaveAttribute('href', '/vision/actions');
});
