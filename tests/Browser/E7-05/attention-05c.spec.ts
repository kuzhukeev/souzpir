import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
let server: ViteDevServer;
let url: string;
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E7-05/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  url = new URL('/tests/Browser/E7-05/fixture-05c.html?attention', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test('события, фильтры, пустое состояние и ошибка загрузки', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(url);
  await expect(page.getByRole('table').getByText('−24%')).toBeVisible();
  await expect(page.getByRole('table').getByText('Неизвестные вопросы')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e7-05c/attention.png', fullPage: true });
  await page.getByLabel('Важность').selectOption('high');
  await expect(page.getByRole('table').getByText('Неизвестные вопросы')).toHaveCount(0);
  await page.getByPlaceholder('Найти событие').fill('отсутствует');
  await expect(page.getByText('Событий по выбранному фильтру нет.')).toBeVisible();
  await page.route('**/seller/attention?**', route => route.abort());
  await page.getByRole('button', { name: 'Показать' }).click();
  await expect(page.getByRole('alert')).toContainText('Не удалось загрузить события');
});
