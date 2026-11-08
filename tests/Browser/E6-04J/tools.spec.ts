import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E6-04J/vite.config.ts', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E6-04J/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('источники и запрос действия доступны на трёх ширинах', async ({ page }) => {
 for (const width of [1440, 1024, 390]) {
  await page.setViewportSize({ width, height: 900 }); await page.goto(url);
  const source = page.getByLabel('Источник прайса'); await expect(source).toHaveValue('file');
  await source.selectOption('catalog'); await expect(page.getByText('Каталог загружен.', { exact: true })).toBeVisible();
  await source.selectOption('link'); await page.getByLabel('Ссылка на прайс').fill('https://example.test/price');
  await page.getByRole('button', { name: /Запросить действие/ }).click();
  await expect(page.getByRole('heading', { name: 'Запросить действие v2' })).toBeVisible();
  await page.getByLabel('Какое действие вам нужно').fill('Добавить расчёт упаковки');
  await expect(page.getByRole('button', { name: 'Отправить заявку' })).toBeEnabled();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
  if (width === 1440) { await mkdir('.local/screenshots/SP-E6-04J', { recursive: true }); await page.screenshot({ path: '.local/screenshots/SP-E6-04J/tools.png', fullPage: true }); }
 }
});

test('форма заявки отправляет описание и ключ без реальной доставки', async ({ page }) => {
 await page.route('**/seller/scenario/tools/request', route => route.abort());
 await page.goto(url);
 await page.getByRole('button', { name: /Запросить действие/ }).click();
 await page.getByLabel('Какое действие вам нужно').fill('Добавить расчёт упаковки');
 const sent = page.waitForRequest(request => request.url().endsWith('/seller/scenario/tools/request') && request.method() === 'POST');
 await page.getByRole('button', { name: 'Отправить заявку' }).click();
 expect((await sent).postDataJSON()).toMatchObject({ description: 'Добавить расчёт упаковки', submission_key: '01J00000000000000000000000' });
 await expect(page.getByRole('alert')).toContainText('Не удалось связаться с сервером');
});
