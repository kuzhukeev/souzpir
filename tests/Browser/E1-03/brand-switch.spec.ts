import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let fixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, colorScheme: 'light', launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E1-03/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  fixture = new URL('/tests/Browser/E1-03/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

test('переключатель доступен и очищает данные прежнего бренда', async ({ page }) => {
  await page.goto(fixture);
  const selector = page.getByRole('combobox', { name: 'Выбрать бренд' });
  await expect(selector).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E1-03B/brand-switch.png', fullPage: true });
  await selector.click();
  await page.screenshot({ path: '.local/screenshots/SP-E1-03B/brand-switch-open.png', fullPage: true });
  const request = page.waitForRequest(req => req.url().endsWith('/brand/switch') && req.method() === 'POST');
  await page.getByRole('option', { name: 'Второй бренд' }).click();
  expect((await request).postDataJSON()).toMatchObject({ brand_id: 'brand-b' });
  expect(await page.evaluate(() => (window as Window & { brandCache?: { getQueryCache(): { getAll(): unknown[] } } }).brandCache?.getQueryCache().getAll().length)).toBe(0);
  await expect(page.locator('[data-private-profile]')).toBeHidden();
});

test('без QueryClient страница открывается, но переключение не отправляется', async ({ page }) => {
  let switches = 0;
  await page.route('**/brand/switch', async route => { switches++; await route.abort(); });
  await page.goto(fixture + '?no-provider');
  await expect(page.getByRole('heading', { name: 'Главная' })).toBeVisible();
  await page.getByRole('combobox', { name: 'Выбрать бренд' }).click();
  await page.getByRole('option', { name: 'Второй бренд' }).click();
  await expect(page.getByRole('alert')).toContainText('Не удалось очистить данные прежнего бренда');
  expect(switches).toBe(0);
  await expect(page.locator('[data-private-profile]')).toBeVisible();
});
