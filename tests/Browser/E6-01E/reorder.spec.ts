import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let fixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E6-01E/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  fixture = new URL('/tests/Browser/E6-01C/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

test('перетаскивание за ручку меняет порядок быстрых ответов', async ({ page }) => {
  await page.goto(fixture);
  await page.getByRole('tab', { name: 'Служебные фразы' }).click();
  const first = page.getByRole('textbox', { name: 'Шаблон 1' });
  const second = page.getByRole('textbox', { name: 'Шаблон 2' });
  await expect(first).toHaveValue('Спасибо за вопрос');
  await expect(second).toHaveValue('Уточню и вернусь с ответом');

  await page.getByLabel(/Переместить шаблон 2/).dragTo(page.getByLabel(/Переместить шаблон 1/));

  await expect(first).toHaveValue('Уточню и вернусь с ответом');
  await expect(second).toHaveValue('Спасибо за вопрос');
  await page.screenshot({ path: '.local/screenshots/SP-E6-01E/quick-replies-reorder.png', fullPage: true });
});

test('Alt+стрелка на ручке перемещает шаблон с клавиатуры', async ({ page }) => {
  await page.goto(fixture);
  await page.getByRole('tab', { name: 'Служебные фразы' }).click();
  const first = page.getByRole('textbox', { name: 'Шаблон 1' });
  const second = page.getByRole('textbox', { name: 'Шаблон 2' });
  await page.getByLabel(/Переместить шаблон 2/).focus();
  await page.keyboard.press('Alt+ArrowUp');
  await expect(first).toHaveValue('Уточню и вернусь с ответом');
  await expect(second).toHaveValue('Спасибо за вопрос');
});

test('кнопок ↑/↓ больше нет, есть ручка и кнопка удаления', async ({ page }) => {
  await page.goto(fixture);
  await page.getByRole('tab', { name: 'Служебные фразы' }).click();
  await expect(page.getByLabel('Поднять шаблон 1')).toHaveCount(0);
  await expect(page.getByLabel(/Переместить шаблон 1/)).toBeVisible();
  await expect(page.getByLabel('Удалить шаблон 1')).toBeVisible();
});
