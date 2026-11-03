import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let vite: ViteDevServer;
const fixture = 'http://127.0.0.1:5299/tests/Browser/E4-04C/fixture.html';
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { vite = await createServer({ configFile: 'tests/Browser/E4-04C/vite.config.ts' }); await vite.listen(); });
test.afterAll(async () => { await vite?.close(); });

test('частота темы, нулевое «не знаю» и примеры в панели', async ({ page }) => {
  await page.goto(fixture);
  await expect(page.getByRole('meter', { name: 'Частота темы Доставка' })).toHaveAttribute('aria-valuenow', '42');
  await expect(page.getByRole('row', { name: /Доставка ask_delivery/ })).toContainText('—');
  await page.screenshot({ path: '.local/screenshots/e4-04c/topics.png', fullPage: true });
  await page.getByRole('button', { name: 'Редактировать' }).first().click();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByText('Как доставляете?')).toBeVisible();
  await dialog.getByRole('button', { name: 'Добавить как пример' }).first().click();
  await expect(dialog.getByText('Когда привезёте заказ?')).toHaveCount(2);
  await dialog.getByRole('textbox', { name: 'Поиск фактов' }).fill('Сроки');
  await expect(dialog.getByRole('checkbox', { name: /Сроки доставки/ })).toBeVisible();
  await expect(dialog.getByRole('checkbox', { name: /Оплата/ })).toHaveCount(0);
  await page.screenshot({ path: '.local/screenshots/e4-04c/topic-panel.png', fullPage: true });
});
