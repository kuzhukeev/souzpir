import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, colorScheme: 'light', launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E6-04K/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  const base = server.resolvedUrls!.local[0];
  url = new URL('/tests/Browser/E6-04K/fixture.html', base).href;
});
test.afterAll(async () => { await server?.close(); });

test('D-5.7: журнал вызовов показывает значения у всех действий, включая выключенное', async ({ page }) => {
  await page.goto(url);
  await expect(page.getByRole('row', { name: /Отправить прайс/ })).toContainText('3');
  await expect(page.getByRole('row', { name: /Посчитать доставку/ })).toContainText('7');
  await expect(page.getByRole('row', { name: /Поставить напоминание/ })).toContainText('2');
  await expect(page.getByRole('row', { name: /Записать обещание/ })).toContainText('5');
  await expect(page.getByRole('row', { name: /Передать менеджеру/ })).toContainText('1');
  await expect(page.getByRole('row', { name: /Записать неизвестный вопрос/ })).toContainText('4');
  await expect(page.getByRole('row', { name: /Отправить реквизиты/ })).toContainText('1');
  const stockRow = page.getByRole('row', { name: /Проверить наличие/ });
  await expect(stockRow).toContainText('нет данных');
  await expect(stockRow.getByRole('switch')).not.toBeChecked();
  await mkdir('.local/screenshots/SP-E6-04K', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E6-04K/tools-1440.png', fullPage: true });
});

test('D-5.7: без горизонтального переполнения на 1440/1024/390', async ({ page }) => {
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(url);
    await expect(page.getByRole('columnheader', { name: 'Вызовы за 30 дней' })).toBeVisible();
    const size = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, viewport: innerWidth }));
    expect(size.scroll, `overflow at ${width}px`).toBeLessThanOrEqual(size.viewport);
  }
});
