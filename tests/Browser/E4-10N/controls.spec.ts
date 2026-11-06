import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-10N/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E4-10N/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('источник, переключатель и меню доступны с клавиатуры', async ({ page }) => {
 await page.goto(url);
 await expect(page.getByLabel('Источник образца 1')).toHaveValue('manual');
 await expect(page.getByLabel('Источник образца 2')).toContainText('Из диалога #A1B2C3');
 const toggle = page.getByRole('switch', { name: 'Включён образец 1' });
 await toggle.focus(); await page.keyboard.press('Space');
 await expect(toggle).toHaveAttribute('aria-checked', 'false');
 const range = page.getByLabel('Строгость образца 1');
 await range.focus(); await page.keyboard.press('ArrowLeft');
 await expect(range).toHaveValue('0.89');
 const menu = page.getByLabel('Действия образца 1');
 await menu.focus(); await page.keyboard.press('Enter');
 await expect(page.getByRole('button', { name: 'Сбросить изменения' })).toBeVisible();
 await page.keyboard.press('Escape');
 await expect(menu).toBeFocused();
 await menu.press('Enter'); await page.getByRole('button', { name: 'Сбросить изменения' }).click();
 await expect(toggle).toHaveAttribute('aria-checked', 'true');
 await mkdir('.local/screenshots/SP-E4-10N', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E4-10N/cards.png', fullPage: true });
});
test('диалог поиска показывает ошибки и закрывается Escape', async ({ page }) => {
 await page.route('**/seller/knowledge/fewshot/dialogs?*', route => route.fulfill({ status: 500, json: { message: 'error' } }));
 await page.goto(url);
 await page.getByRole('button', { name: 'Добавить из диалога' }).first().click();
 const dialog = page.getByRole('dialog', { name: 'Добавить из диалога' });
 await expect(dialog).toBeVisible();
 await dialog.getByRole('button', { name: 'Найти' }).click();
 await expect(dialog.getByRole('alert')).toContainText('Не удалось загрузить диалоги.');
 await mkdir('.local/screenshots/SP-E4-10N', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E4-10N/dialog.png' });
 await page.keyboard.press('Escape');
 await expect(dialog).toBeHidden();
});
test('карточки и пустое состояние доступны в трёх темах', async ({ page }) => {
 for (const theme of ['neutral', 'confectionery', 'pyro']) {
  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(url + '?theme=' + theme);
  await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
  await expect(page.getByRole('switch', { name: 'Включён образец 1' })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.goto(url + '?empty=1&theme=' + theme);
  await expect(page.getByRole('heading', { name: 'Образцов речи пока нет' })).toBeVisible();
 }
});
