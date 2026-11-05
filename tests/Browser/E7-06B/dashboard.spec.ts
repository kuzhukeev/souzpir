import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
let server: ViteDevServer;
let fixture: string;
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E7-06B/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  fixture = new URL('/tests/Browser/E7-06B/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test('дельты, каналы, проблемы, пустое состояние и ошибка', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(fixture);
  const indicators = page.getByRole('region', { name: 'Показатели' });
  await expect(indicators.getByText('+2 за предыдущий период')).toBeVisible();
  await expect(page.getByRole('columnheader', { name: 'Конверсия' })).toBeVisible();
  await expect(page.getByRole('columnheader', { name: 'Где исправить' })).toBeVisible();
  await expect(page.getByText('-10 п.п.')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e7-06b/summary.png', fullPage: true });
  await page.goto(`${fixture}?empty`);
  await expect(page.getByRole('heading', { name: 'Пока нет диалогов' })).toBeVisible();
  await expect(page.getByRole('region', { name: 'Показатели' })).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Качество и изменение KPI' })).toHaveCount(0);
  await page.goto(`${fixture}?error`);
  await expect(page.getByRole('alert')).toContainText('Код LOAD_ERROR');
  await expect(page.getByRole('button', { name: 'Повторить' })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e7-06b/error.png', fullPage: true });
});
test('обновление показывает загрузку и ошибку', async ({ page }) => {
  await page.goto(fixture);
  await page.route('**/seller?**', async route => {
    await new Promise(resolve => setTimeout(resolve, 700));
    await route.abort();
  });
  await page.getByRole('button', { name: 'Показать' }).click();
  await expect(page.getByRole('status')).toContainText('Загружаем сводку');
  await expect(page.getByRole('alert')).toContainText('Не удалось загрузить сводку');
});
