import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let fixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-21M/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); fixture = new URL('/tests/Browser/E8-21M/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('анализ показан как отдельный четвёртый этап',async({page})=>{await page.goto(`${fixture}?analyzing`);await expect(page.getByText('Анализируем полученные данные')).toBeVisible();await expect(page.getByText('Анализ данных · выполняется')).toBeVisible();await expect(page.getByRole('progressbar',{name:'Проверка сайта'})).toHaveAttribute('max','4');await mkdir('.local/screenshots/SP-E8-21M',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E8-21M/progress.png',fullPage:true});});

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
  await expect(page.getByText('Обнаружены признаки: reCAPTCHA')).toBeVisible();
  await expect(page.getByText(/Обработано адресов: 2 из 4/)).toBeVisible();
  await page.getByText('Структурированные данные', { exact: true }).click();
  await page.getByText('Для разработчика').first().click();
  await expect(page.getByText('Найденный фрагмент структурированных данных с проверенной страницы.').first()).toBeVisible();
  await expect(page.locator('pre').filter({ hasText: 'Проверенный товар' }).first()).toBeVisible();
  await page.getByText('Контакты', { exact: true }).first().click();
  await page.getByText('Для разработчика').nth(1).click();
  await expect(page.getByText('Пример структуры; подставьте реальные сведения вашей компании.').nth(1)).toBeVisible();
  await expect(page.locator('pre').filter({ hasText: 'Для чего он предназначен' })).toBeVisible();
  await expect(page.getByRole('row', { name: /Связаться с нами Контакты/ })).toBeVisible();
  await expect(page.getByRole('row', { name: /Каталог Товар/ })).toBeVisible();
  await page.getByRole('button', { name: 'Подробнее: Каталог' }).click();
  await expect(page.getByText('Название: Товар')).toBeVisible();
  await page.getByRole('button', { name: 'Закрыть' }).click();
  await page.getByRole('button', { name: 'Уведомить, когда будет готово' }).click();
  await expect(page.getByText('Уведомим, когда проверка завершится, пока эта страница открыта.')).toBeVisible();
  await mkdir('.local/screenshots/SP-E8-21M', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E8-21M/progress.png', fullPage: true });
  await page.getByRole('button', { name: 'Завершить проверку' }).click();
  await expect(page.getByText('Проверка завершена', { exact: true }).first()).toBeVisible();
  await expect.poll(() => page.evaluate(() => (window as Window & { notifications?: string[] }).notifications?.length)).toBe(1);
});
