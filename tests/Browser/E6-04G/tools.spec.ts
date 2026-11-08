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
  await expect(page.getByRole('columnheader', { name: 'Действие' })).toBeVisible();
  await expect(page.getByRole('columnheader', { name: 'Вызовы за 30 дней' })).toBeVisible();
  await expect(page.getByText('Рассчитывает стоимость по настроенным зонам.')).toBeVisible();
  await expect(page.getByText('Высылает реквизиты компании для оплаты.')).toBeVisible();
  await expect(page.getByRole('row', { name: /Записать обещание/ })).toContainText('5');

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
  await expect(page.getByRole('alert').filter({ hasText: 'Не удалось загрузить действия' })).toBeVisible();
  await expect(page.getByText('Код ошибки: ERR_LOAD')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Повторить' })).toBeVisible();
  await page.unroute('**' + pagePath);
  await mkdir('.local/screenshots/SP-E6-04G', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E6-04G/tools-1440.png', fullPage: true });
});

test('пустые данные о вызовах предлагают обновить', async ({ page }) => {
  await page.goto(emptyUrl);
  await expect(page.getByText('Данные о вызовах пока недоступны.')).toBeVisible();
  await expect(page.getByRole('cell', { name: 'нет данных' }).first()).toBeVisible();
});

test('D-5.7: без горизонтального переполнения и критичных нарушений доступности на трёх ширинах', async ({ page }) => {
  const findings: string[] = [];
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(url);
    await expect(page.getByText('Рассчитывает стоимость по настроенным зонам.')).toBeVisible();
    const size = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, viewport: innerWidth }));
    expect(size.scroll, `overflow at ${width}px`).toBeLessThanOrEqual(size.viewport);
    await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
    const violations = await page.evaluate(async () => {
      const axe = (window as unknown as { axe: { run: (root: Document, options: object) => Promise<{ violations: { id: string; impact: string }[] }> } }).axe;
      return (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } })).violations.filter(v => v.impact === 'critical' || v.impact === 'serious').map(v => v.id);
    });
    findings.push(...violations.map(id => `${width}: ${id}`));
  }
  expect(findings).toEqual([]);
});
