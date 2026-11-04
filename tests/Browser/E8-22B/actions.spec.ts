import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
let server: ViteDevServer;
let url: string;
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E8-22B/vite.config.ts', server: { host: '127.0.0.1', port: 0 } });
  await server.listen();
  url = new URL('/tests/Browser/E8-22B/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('recommendation shows missing data honestly', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(url);
  await expect(page.getByText('Приоритет:')).toBeVisible();
  await expect(page.getByText('Затронутые вопросы:')).toBeVisible();
  await expect(page.getByText('Шаги:')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E8-22B/actions.png', fullPage: true });
});
test('history shows completion and preserves effect link', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${url}?history`);
  const table = page.getByRole('table');
  await expect(table).toContainText('01.11.2026');
  await expect(table).toContainText('Нет данных');
  await expect(table).toContainText('Рано судить');
  const action = page.getByRole('article').filter({ hasText: 'Добавить раздел с условиями' });
  await expect(action).toBeVisible();
  await expect(action.getByText('Комментарий: Сведения обновлены')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Посмотреть эффект' })).toHaveAttribute('href', '/vision/actions/effect');
  await page.screenshot({ path: '.local/screenshots/SP-E8-22B/history.png', fullPage: true });
});
test('read only view has no management controls', async ({ page }) => {
  await page.goto(`${url}?readonly`);
  await expect(page.getByText('Добавить раздел с условиями')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Отметить выполненным' })).toHaveCount(0);
  await expect(page.getByText('Добавить действие')).toHaveCount(0);
});
