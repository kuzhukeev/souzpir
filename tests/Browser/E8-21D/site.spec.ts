import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let fixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-21D/vite.config.ts' }); await server.listen(); fixture = new URL('/tests/Browser/E8-21D/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });

test('проверенные данные и уведомление по завершении', async ({ page }) => {
  await page.addInitScript(() => {
    const browser = window as Window & { notifications?: string[] };
    browser.notifications = [];
    class FakeNotification {
      static permission = 'granted';
      static async requestPermission() { return 'granted'; }
      constructor(title: string) { browser.notifications?.push(title); }
    }
    Object.defineProperty(window, 'Notification', { value: FakeNotification });
  });
  await page.goto(fixture);
  await page.getByText('Структурированные данные', { exact: true }).click();
  await page.getByText('Для разработчика').first().click();
  await expect(page.getByText('Найденный фрагмент структурированных данных с проверенной страницы.').first()).toBeVisible();
  await expect(page.locator('pre').filter({ hasText: 'Проверенный товар' }).first()).toBeVisible();
  await page.getByText('Контакты', { exact: true }).first().click();
  await page.getByText('Для разработчика').nth(1).click();
  await expect(page.getByText('Пример кода для этого критерия не предоставлен.')).toBeVisible();
  await expect(page.getByRole('row', { name: /Связаться с нами Контакты/ })).toBeVisible();
  await expect(page.getByRole('row', { name: /Каталог Не определён/ })).toBeVisible();
  await page.getByRole('button', { name: 'Уведомить, когда будет готово' }).click();
  await expect(page.getByText('Уведомим, когда проверка завершится, пока эта страница открыта.')).toBeVisible();
  await mkdir('.local/screenshots/SP-E8-21D', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E8-21D/progress.png', fullPage: true });
  await page.getByRole('button', { name: 'Завершить проверку' }).click();
  await expect(page.getByText('Проверка завершена', { exact: true }).first()).toBeVisible();
  await expect.poll(() => page.evaluate(() => (window as Window & { notifications?: string[] }).notifications?.length)).toBe(1);
});
