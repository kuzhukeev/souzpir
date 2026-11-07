import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined } });
let server: ViteDevServer;
let url: string;
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E6-08F/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  url = new URL('/tests/Browser/E6-08F/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('каталог показывает товары, источники и меню действий', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(url);
  await expect(page.getByRole('button', { name: 'Google Sheets · Скоро' })).toBeDisabled();
  await expect(page.getByRole('link', { name: 'Настройки каталога' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'История импортов' })).toBeVisible();
  await page.getByLabel('Действия: Товар').click();
  await expect(page.getByRole('button', { name: 'Удалить' })).toBeVisible();
  await page.screenshot({ path: '.local/runtime/e6-08f-catalog.png', fullPage: true });
});

test('настройки каталога показывают тумблеры бренда и историю импортов', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${url}?settings`);
  await expect(page.getByRole('switch', { name: 'Бот может называть цены' })).toHaveAttribute('aria-checked', 'true');
  await expect(page.getByRole('switch', { name: 'Бот может называть скидки бренда' })).toHaveAttribute('aria-checked', 'false');
  await expect(page.getByRole('button', { name: 'Отправить себе' })).toBeVisible();
  const history = page.getByRole('region', { name: 'История импортов' });
  await expect(history.getByRole('columnheader', { name: 'Источник' })).toBeVisible();
  await expect(history).toContainText('Каталог.xlsx');
  await expect(history).toContainText('YML по ссылке');
  await expect(history).toContainText('Не удалось прочитать файл');
});

test('ошибка каталога позволяет повторить загрузку', async ({ page }) => {
  await page.goto(`${url}?error`);
  await expect(page.getByRole('alert')).toContainText('Не удалось загрузить каталог');
  await expect(page.getByRole('button', { name: 'Повторить' })).toBeVisible();
});

test('пример фразы берёт признак «мало» из check_stock', async ({ page }) => {
  await page.goto(`${url}?stock`);
  await expect(page.getByText(/осталось мало \(4\)/)).toBeVisible();
});
