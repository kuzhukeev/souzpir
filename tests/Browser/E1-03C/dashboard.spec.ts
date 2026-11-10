import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let base: string;
test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E1-03C/vite.config.ts', server: { host: '127.0.0.1', port: 0 } });
  await server.listen();
  base = server.resolvedUrls!.local[0];
  await mkdir('.local/screenshots/sp-e1-03c', { recursive: true });
});
test.afterAll(async () => { await server?.close(); });

test('главная: период, KPI с дельтами, легенды, проблемы, каналы, видение', async ({ page }) => {
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`${base}tests/Browser/E1-03C/fixture.html?screen=home`);
    await expect(page.getByLabel('Период')).toHaveValue('7');
    await expect(page.getByText('+0,3', { exact: true })).toBeVisible();
    await expect(page.getByText('К предыдущим 7 дням').first()).toBeVisible();
    await expect(page.getByLabel('Группировка графика')).toHaveValue('day');
    await expect(page.getByText('22 окт', { exact: true })).toBeVisible();
    await expect(page.getByText('28 окт', { exact: true })).toBeVisible();
    await expect(page.getByText('+6 п.п.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Где исправить →' })).toBeVisible();
    await expect(page.getByText('Диалоги', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('Получили контакт', { exact: true }).first()).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Главные проблемы' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Статус каналов' })).toBeVisible();
    await expect(page.getByText('Сайт', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('Telegram', { exact: true }).first()).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Видение' })).toBeVisible();
    await expect(page.getByText('Открыть отчёт →')).toBeVisible();
    const size = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, viewport: innerWidth }));
    expect(size.scroll, `overflow at ${width}px`).toBeLessThanOrEqual(size.viewport);
    if (width === 1440) {
      await page.getByLabel('Группировка графика').selectOption('week');
      await expect(page.getByText('22 окт–28 окт')).toBeVisible();
      await page.getByLabel('Группировка графика').selectOption('day');
    }
    if (width === 1440) await page.screenshot({ path: '.local/screenshots/sp-e1-03c/home-1440.png', fullPage: true });
  }
});

test('требует вашего действия: фильтр модулей и отложение', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${base}tests/Browser/E1-03C/fixture.html?screen=attention`);
  await expect(page.getByRole('heading', { name: 'Требует вашего действия' })).toBeVisible();
  await page.getByLabel('Модуль').selectOption('seller');
  await expect(page.getByText('Диалоги ждут менеджера')).toBeVisible();
  await expect(page.getByText('Отложить на 7 дней').first()).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e1-03c/attention-1440.png', fullPage: true });
});

test('уведомления: вкладки, пиктограммы и подписи модулей', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${base}tests/Browser/E1-03C/fixture.html?screen=notifications`);
  await expect(page.getByRole('heading', { name: 'Уведомления' })).toBeVisible();
  const tabs = page.getByRole('navigation', { name: 'Фильтр уведомлений' });
  await expect(tabs.getByRole('button', { name: 'Видение' })).toBeVisible();
  await expect(tabs.getByRole('button', { name: 'Продавец' })).toBeVisible();
  await expect(page.getByText('Диалог ждёт менеджера')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e1-03c/notifications-1440.png', fullPage: true });
});

test('S-1.1: главная без критичных нарушений доступности в трёх темах и на трёх ширинах', async ({ page }) => {
  const findings: string[] = [];
  for (const theme of ['neutral', 'pyro', 'confectionery']) {
    for (const width of [1440, 1024, 390]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${base}tests/Browser/E1-03C/fixture.html?screen=home`);
      await expect(page.getByRole('heading', { name: 'Главные проблемы' })).toBeVisible();
      await page.evaluate((value) => { document.documentElement.dataset.theme = value; }, theme);
      await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
      const violations = await page.evaluate(async () => {
        const axe = (window as unknown as { axe: { run: (root: Document, options: object) => Promise<{ violations: { id: string; impact: string }[] }> } }).axe;
        return (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } })).violations.filter(v => v.impact === 'critical' || v.impact === 'serious').map(v => v.id);
      });
      findings.push(...violations.map(id => `${theme}/${width}: ${id}`));
    }
  }
  expect(findings).toEqual([]);
});
