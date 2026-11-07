import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.use({
  viewport: { width: 1440, height: 900 },
  launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
    ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH }
    : {},
});
test.beforeAll(async () => {
  server = await createServer({
    configFile: 'tests/Browser/E8-20Y/vite.config.ts',
    server: { host: '127.0.0.1', port: 0, watch: null },
  });
  await server.listen();
  url = new URL('/tests/Browser/E8-20Y/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => {
  await server?.close();
});

test('баннер «Пересчитываем» опрашивает reload до готовности отчёта', async ({ page }) => {
  let count = 0;
  await page.route('**/tests/Browser/E8-20Y/fixture.html', async (route) => {
    if (!route.request().headers()['x-inertia']) return route.continue();
    count++;
    const mergeProgress = count === 1 ? { stage: 'recalculating' } : null;
    return route.fulfill({
      status: 200,
      headers: { 'X-Inertia': 'true' },
      json: {
        component: 'Fixture',
        props: { mergeProgress },
        rescuedProps: ['entities', 'history', 'distinctPairs'],
        url: new URL(route.request().url()).pathname,
        version: 'fixture',
      },
    });
  });
  await page.goto(url);
  const banner = page.getByRole('status').filter({ hasText: 'Пересчитываем, 1–2 минуты' });
  await expect(banner).toBeVisible();
  await mkdir('.local/screenshots/SP-E8-20Y', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E8-20Y/recalculating.png' });
  await expect(banner).toBeHidden({ timeout: 6000 });
  expect(count).toBe(2);
});

test('ошибка пересчёта показывает сообщение и прекращает опрос', async ({ page }) => {
  let count = 0;
  await page.route('**/tests/Browser/E8-20Y/fixture.html', async (route) => {
    if (!route.request().headers()['x-inertia']) return route.continue();
    count++;
    return route.fulfill({
      status: 200,
      headers: { 'X-Inertia': 'true' },
      json: {
        component: 'Fixture',
        props: { mergeProgress: { stage: 'failed' } },
        rescuedProps: ['entities', 'history', 'distinctPairs'],
        url: new URL(route.request().url()).pathname,
        version: 'fixture',
      },
    });
  });
  await page.goto(url);
  await expect(page.getByRole('alert')).toContainText('Не удалось пересчитать отчёт');
  await page.screenshot({ path: '.local/screenshots/SP-E8-20Y/failed.png' });
  const countAfterFirstPoll = count;
  await page.waitForTimeout(2500);
  expect(count).toBe(countAfterFirstPoll);
});
