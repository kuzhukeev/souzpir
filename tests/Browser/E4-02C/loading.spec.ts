import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-02C/vite.config.ts', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E4-02C/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });

test('список фактов показывает узел «Без темы»', async ({ page }) => {
  await page.goto(url);
  await expect(page.getByRole('heading', { name: 'Факты и группы' })).toBeVisible();
  const node = page.getByRole('button', { name: /Без темы/ });
  await expect(node).toBeVisible();
  await node.click();
  await expect(page.getByRole('cell', { name: 'Факт без темы', exact: true })).toBeVisible();
  await mkdir('.local/screenshots/SP-E4-02C', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E4-02C/list.png', fullPage: true });
});

test('карточка факта: skeleton загрузки, ошибка, повтор и бейдж «Черновик»', async ({ page }) => {
  let count = 0;
  let release!: () => void;
  const pending = new Promise<void>(resolve => { release = resolve; });
  const loaded = {
    fact: { id: 'f1', text: 'Доставка по России', status: 'active', source: 'manual', group_key: 'delivery', change_status: 'new' },
    version: 1, history: [], publishedText: null,
    topics: [{ key: 'delivery', label: 'Доставка', bridge_hint: '', must_not: [] }],
    conditionOptions: [], intentLinks: [], group: { topic: 'Доставка', bridge_hint: '', must_not: [] }, example: null,
  };
  await page.route('**/tests/Browser/E4-02C/fixture.html*', async route => {
    if (!route.request().headers()['x-inertia']) return route.continue();
    count++;
    if (count === 1) await pending;
    const payload = count === 1
      ? { component: 'Fixture', props: {}, rescuedProps: ['factData'], url: new URL(url).pathname, version: 'fixture' }
      : { component: 'Fixture', props: { factData: loaded }, url: new URL(url).pathname, version: 'fixture' };
    return route.fulfill({ status: 200, headers: { 'X-Inertia': 'true' }, json: payload });
  });
  await page.goto(`${url}?pending`);
  await expect(page.getByRole('status').filter({ hasText: 'Загружаем карточку факта' })).toBeAttached();
  await mkdir('.local/screenshots/SP-E4-02C', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E4-02C/loading.png', fullPage: true });
  release();
  await expect(page.getByRole('alert')).toContainText('Не удалось загрузить факт');
  await page.screenshot({ path: '.local/screenshots/SP-E4-02C/error.png', fullPage: true });
  await page.getByRole('button', { name: 'Повторить' }).click();
  await expect(page.getByRole('heading', { name: 'Карточка факта' })).toBeVisible();
  await expect(page.getByText('Черновик')).toBeVisible();
  await expect(page.getByLabel('Факт')).toHaveValue('Доставка по России');
  await page.screenshot({ path: '.local/screenshots/SP-E4-02C/card-loaded.png', fullPage: true });
  expect(count).toBe(2);
});
