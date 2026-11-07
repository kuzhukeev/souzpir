import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let fixture: string;
let notificationsFixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E1-09E/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  fixture = new URL('/tests/Browser/E1-09E/fixture.html', server.resolvedUrls!.local[0]).href;
  notificationsFixture = new URL('/tests/Browser/E1-09E/notifications-fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

test('журнал показывает имя и ссылку объекта', async ({ page }) => {
  await page.goto(fixture);
  await expect(page.getByRole('link', { name: 'Диалог с Иваном' })).toHaveAttribute('href', /seller\/dialogs/);
  await page.getByRole('button', { name: 'Действия с записью' }).first().click();
  await page.getByRole('button', { name: 'Детали действия', exact: true }).click();
  await expect(page.getByText('Диалог с Иваном').last()).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E1-09E/journal.png', fullPage: true });
});

test('история уведомлений: меню строки, статус отложено, ошибка загрузки', async ({ page }) => {
  await page.goto(notificationsFixture);
  const pendingBadge = page.locator('table').getByText('Отложено');
  await expect(pendingBadge).toBeVisible();
  await expect(pendingBadge).toHaveClass(/color-warning-soft/);
  await page.getByRole('button', { name: 'Действия с записью' }).first().click();
  await page.getByRole('button', { name: 'Детали уведомления', exact: true }).click();
  await expect(page.getByText('Попыток доставки')).toBeVisible();
  await page.keyboard.press('Escape');

  await page.route('**/journal/notifications*', (route) => route.fulfill({ status: 500, body: 'Ошибка' }));
  await page.getByRole('button', { name: 'Применить' }).click();
  await expect(page.getByText('Не удалось загрузить историю уведомлений')).toBeVisible();
  await expect(page.getByText('Код: ERR_LOAD')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Повторить' }).first()).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E1-09E/notifications-error.png', fullPage: true });
});

test('история уведомлений: ширины, темы и клавиатура', async ({ page }) => {
  for (const theme of ['neutral', 'confectionery', 'pyro']) {
    for (const width of [1440, 1024, 390]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(notificationsFixture + '?theme=' + theme);
      await expect(page.getByRole('heading', { name: 'История уведомлений' })).toBeVisible();
      await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
      const violations = await page.evaluate(async () => {
        const axe = (window as unknown as { axe: { run: (root: Document, options: object) => Promise<{ violations: { id: string; impact: string }[] }> } }).axe;
        return (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } })).violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
      });
      expect(violations, theme + '@' + width + ': ' + JSON.stringify(violations)).toEqual([]);
    }
  }
  await page.getByRole('button', { name: 'Действия с записью' }).first().focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('button', { name: 'Детали уведомления', exact: true })).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('button', { name: 'Детали уведомления', exact: true })).toBeHidden();
});
