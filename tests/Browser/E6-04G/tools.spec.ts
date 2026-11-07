import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string; let emptyUrl: string;
test.use({ viewport: { width: 1440, height: 900 }, colorScheme: 'light', launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E6-04G/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  const base = server.resolvedUrls!.local[0];
  url = new URL('/tests/Browser/E6-04G/fixture.html', base).href;
  emptyUrl = new URL('/tests/Browser/E6-04G/fixture.html?empty', base).href;
});
test.afterAll(async () => { await server?.close(); });

test('описание действий, загрузка и ошибка обновления вызовов', async ({ page }) => {
  await page.goto(url);
  await expect(page.getByText('Рассчитывает стоимость по настроенным зонам.')).toBeVisible();
  await expect(page.getByText('Высылает реквизиты компании для оплаты.')).toBeVisible();
  await expect(page.getByText('Вызовы за 30 дней: 5')).toBeVisible();

  const pagePath = new URL(url).pathname;
  let release = () => {};
  const wait = new Promise<void>(resolve => { release = resolve; });
  await page.route('**' + pagePath, async route => {
    if (!route.request().headers()['x-inertia']) { await route.continue(); return; }
    await wait;
    await route.fulfill({
      status: 200,
      headers: { 'X-Inertia': 'true', 'Content-Type': 'application/json', Vary: 'X-Inertia' },
      body: JSON.stringify({ component: 'Fixture', props: { callCounts: {}, toolsLoadError: true }, url: pagePath, version: null, clearHistory: false, encryptHistory: false }),
    });
  });
  await page.getByRole('button', { name: 'Обновить' }).first().click();
  await expect(page.getByRole('status')).toBeVisible();
  release();
  await expect(page.getByRole('alert').filter({ hasText: 'Не удалось загрузить вызовы действий' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Повторить' })).toBeVisible();
  await page.unroute('**' + pagePath);
  await mkdir('.local/screenshots/SP-E6-04G', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E6-04G/tools-1440.png', fullPage: true });
});

test('пустые данные о вызовах предлагают обновить', async ({ page }) => {
  await page.goto(emptyUrl);
  await expect(page.getByText('Данные о вызовах пока недоступны.')).toBeVisible();
  await expect(page.getByText('Вызовы за 30 дней: нет данных').first()).toBeVisible();
});
