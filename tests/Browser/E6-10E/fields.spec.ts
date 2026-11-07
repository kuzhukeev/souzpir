import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let fixture: string;
let base: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E6-10E/vite.config.ts', cacheDir: '.local/runtime/vite-e610e', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  base = server.resolvedUrls!.local[0];
  fixture = new URL('/tests/Browser/E6-10E/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('schema shows published fields and scroll regions are keyboard accessible', async ({ page }) => {
  await page.goto(fixture);
  await page.getByRole('button', { name: 'Схема полей' }).click();
  const dialog = page.getByRole('dialog', { name: 'Схема полей' });
  await expect(dialog.getByText('Город', { exact: true })).toBeVisible();
  await expect(dialog.getByText('Марка и модель', { exact: true })).toBeVisible();
  await mkdir('.local/screenshots/SP-E6-10E', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E6-10E/schema.png', fullPage: true, animations: 'disabled' });
  await dialog.getByRole('button', { name: 'Закрыть' }).click();
  for (const width of [1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    for (const name of ['Опубликованные дополнительные поля', 'Поля карточки клиента']) {
      const region = page.getByRole('region', { name });
      await region.focus();
      await expect(region).toBeFocused();
    }
    await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
    const violations = await page.evaluate(async () => {
      const axe = (window as unknown as { axe: { run: (node: Document, options: object) => Promise<{ violations: { id: string }[] }> } }).axe;
      return (await axe.run(document, { runOnly: ['scrollable-region-focusable'] })).violations;
    });
    expect(violations).toEqual([]);
  }
  await page.goto(fixture + '?school');
  await page.getByRole('button', { name: 'Схема полей' }).click();
  await expect(page.getByRole('dialog', { name: 'Схема полей' }).getByText('Курс', { exact: true })).toBeVisible();
});

test('customer field labels and dialog stage are readable and semantic', async ({ page }) => {
  await page.goto(new URL('/tests/Browser/E6-10E/customer.html', base).href);
  const field = page.getByRole('combobox', { name: 'Дополнительное поле' });
  const value = page.getByRole('textbox', { name: 'Значение поля' });
  await expect(value).toBeDisabled();
  await field.selectOption('car_year');
  await value.fill('2020');
  await expect(value).toHaveValue('2020');
  await page.goto(new URL('/tests/Browser/E6-10E/customer.html?show', base).href);
  await expect(page.getByText('Год выпуска', { exact: true })).toBeVisible();
  await expect(page.getByText('2020', { exact: true })).toBeVisible();
  await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
  const violations = await page.evaluate(async () => {
    const axe = (window as unknown as { axe: { run: (node: Document, options: object) => Promise<{ violations: { id: string }[] }> } }).axe;
    return (await axe.run(document, { runOnly: ['definition-list'] })).violations;
  });
  expect(violations).toEqual([]);
  await page.goto(new URL('/tests/Browser/E6-10E/dialog.html', base).href);
  await expect(page.getByText('Telegram · Потребность')).toBeVisible();
  await page.getByText('Редактировать карточку', { exact: true }).click();
  await expect(page.getByRole('spinbutton', { name: 'Год выпуска' })).toHaveValue('2020');
  await expect(page.getByRole('combobox', { name: 'Услуга' })).toHaveValue('Ремонт');
});
