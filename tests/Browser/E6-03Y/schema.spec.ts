import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let base: string;
test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E6-03Y/vite.config.ts', server: { host: '127.0.0.1', port: 0 } });
  await server.listen();
  base = new URL('/tests/Browser/E6-03Y/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

test('клик по стадии выделяет её и показывает условия, итоги воронки видны', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${base}?state=ready`);
  await expect(page.getByRole('heading', { name: 'Переходы и условия · только чтение' })).toBeVisible();
  await expect(page.getByText('v2 · настраивается командой EYON при внедрении')).toBeVisible();
  for (const outcome of ['У менеджера', 'Закрыт', 'Потерян', 'Спам']) await expect(page.getByText(outcome, { exact: true })).toBeVisible();
  const open = page.getByRole('button', { name: 'Открытие' });
  const budget = page.getByRole('button', { name: 'Бюджет' });
  await expect(open).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByText('Стадия: Открытие')).toBeVisible();
  await budget.click();
  await expect(budget).toHaveAttribute('aria-pressed', 'true');
  await expect(open).toHaveAttribute('aria-pressed', 'false');
  await expect(page.getByText('Стадия: Бюджет')).toBeVisible();
  await mkdir('.local/screenshots/SP-E6-03Y', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E6-03Y/schema-1440.png', fullPage: true });
});

for (const width of [1440, 1024, 390]) {
  for (const theme of ['neutral', 'pyro', 'confectionery']) {
    test(`${theme}: схема воронки без переполнения страницы при ширине ${width}`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${base}?state=ready&theme=${theme}`);
      await expect(page.getByRole('heading', { name: 'Переходы и условия · только чтение' })).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
      await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
      const violations = await page.evaluate(async () => {
        const axe = (window as unknown as { axe: { run: (root: Document, options: object) => Promise<{ violations: { id: string; impact: string }[] }> } }).axe;
        return (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] } })).violations.filter(v => v.impact === 'critical' || v.impact === 'serious');
      });
      expect(violations).toEqual([]);
    });
  }
}

test('схема доступна с клавиатуры: Tab фокусирует блок прокрутки', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(`${base}?state=ready`);
  const schema = page.getByRole('group', { name: 'Схема стадий воронки' });
  await schema.focus();
  await expect(schema).toBeFocused();
});
