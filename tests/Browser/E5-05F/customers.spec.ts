import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir, writeFile } from 'node:fs/promises';

let base: string;
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } });
let vite: ViteDevServer;
test.beforeAll(async () => {
  vite = await createServer({ configFile: 'tests/Browser/E5-05F/vite.config.ts', server: { host: '127.0.0.1', port: 0 } });
  await vite.listen();
  base = new URL('/tests/Browser/E5-05F/fixture.html', vite.resolvedUrls!.local[0]).href;
  await mkdir('.local/screenshots/sp-e5-05f', { recursive: true });
});
test.afterAll(async () => { await vite?.close(); });

test('customer list, card, and task screens show the missing fields', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${base}?screen=index`);
  await expect(page.getByText('Фейерверк')).toBeVisible();
  await expect(page.getByRole('combobox', { name: 'Размер страницы' })).toHaveValue('20');
  await expect(page.getByRole('combobox', { name: 'Период' })).toHaveValue('30');
  await expect(page.getByRole('cell').getByText('Сайт')).toBeVisible();
  await expect(page.getByRole('cell').getByText('Telegram')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e5-05f/customers-1440.png', fullPage: true });

  await page.goto(`${base}?screen=show`);
  await expect(page.getByText('Фейерверк · Тверь')).toBeVisible();
  await expect(page.getByText('12345')).toBeVisible();
  await expect(page.getByText('Клиент, 3 нояб')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e5-05f/customer-1440.png', fullPage: true });

  await page.goto(`${base}?screen=tasks`);
  await expect(page.getByText('Клиент: Анна Смирнова')).toBeVisible();
  await page.getByRole('button', { name: 'Канбан' }).click();
  await page.screenshot({ path: '.local/screenshots/sp-e5-05f/tasks-1440.png', fullPage: true });
});

test('D-3.1: список клиентов без критичных нарушений доступности на трёх ширинах', async ({ page }) => {
  const findings: Record<number, { id: string; impact: string; nodes: unknown[] }[]> = {};
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`${base}?screen=index`);
    await expect(page.getByText('Анна Смирнова')).toBeVisible();
    await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
    findings[width] = await page.evaluate(async () => {
      const axe = (window as unknown as { axe: { run: (root: Document, options: object) => Promise<{ violations: { id: string; impact: string; nodes: { target: unknown[] }[] }[] }> } }).axe;
      return (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } })).violations.filter(v => v.impact === 'critical' || v.impact === 'serious').map(v => ({ id: v.id, impact: v.impact, nodes: v.nodes.map(node => node.target) }));
    });
    await page.screenshot({ path: `.local/screenshots/sp-e5-05f/customers-${width}.png`, fullPage: true });
  }
  await writeFile('.local/screenshots/sp-e5-05f/customers-axe.json', JSON.stringify(findings, null, 2));
  expect(Object.values(findings).flat()).toEqual([]);
});
