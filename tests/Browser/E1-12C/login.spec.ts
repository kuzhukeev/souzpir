import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E1-12C/vite.config.ts', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E1-12C/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('сообщение после восстановления пароля видно при входе', async ({ page }) => {
 await page.goto(url);
 await expect(page.getByRole('status')).toContainText('Пароль изменён. Войдите снова.');
 await mkdir('.local/screenshots/SP-E1-12C', { recursive: true });
});
test('блокировка предупреждает и не блокирует другой адрес', async ({ page }) => {
 await page.goto(url + '?locked');
 await expect(page.getByRole('alert')).toContainText('Слишком много попыток');
 await expect(page.getByRole('button', { name: 'Войти' })).toBeDisabled();
 await page.getByLabel('Рабочая почта').fill('another@example.test');
 await expect(page.getByRole('button', { name: 'Войти' })).toBeEnabled();
 await page.getByLabel('Рабочая почта').fill('user@example.test');
 await expect(page.getByRole('button', { name: 'Войти' })).toBeDisabled();
 await page.screenshot({ path: '.local/screenshots/SP-E1-12C/login-locked.png', fullPage: true });
});

test('страницы профиля и подключений показывают действующие данные', async ({ page }) => {
 const base = new URL(url);
 await page.goto(new URL('/tests/Browser/E1-12C/integrations.html', base).href);
 await expect(page.getByRole('heading', { name: 'Подключения' })).toBeVisible();
 await expect(page.getByRole('link', { name: /Настроить/i }).first()).toBeVisible();
 await page.screenshot({ path: '.local/screenshots/SP-E1-12C/integrations.png', fullPage: true });
 await page.goto(new URL('/tests/Browser/E1-12C/profile.html', base).href);
 await expect(page.getByRole('textbox', { name: 'Электронная почта' })).toHaveValue('ivan@example.test');
 await page.screenshot({ path: '.local/screenshots/SP-E1-12C/profile.png', fullPage: true });
});

test('восстановление и настройки уведомлений доступны', async ({ page }) => {
 await page.goto(url + '?reset');
 await expect(page.getByRole('heading', { name: 'Новый пароль' })).toBeVisible();
 await page.screenshot({ path: '.local/screenshots/SP-E1-12C/reset.png', fullPage: true });
 await page.goto(new URL('/tests/Browser/E1-12C/profile.html?screen=notifications', url).href);
 await expect(page.getByRole('heading', { name: 'Уведомления', level: 1 })).toBeVisible();
 await page.screenshot({ path: '.local/screenshots/SP-E1-12C/notifications.png', fullPage: true });
});

test('вход остаётся доступным на трёх ширинах и темах', async ({ page }) => {
 for (const [width, theme] of [[1440, 'neutral'], [1024, 'confectionery'], [390, 'pyro']] as const) {
  await page.setViewportSize({ width, height: 900 });
  await page.goto(url + '?locked');
  await page.evaluate((value) => { document.documentElement.dataset.theme = value; }, theme);
  await expect(page.getByRole('alert')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Войти' })).toBeDisabled();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
 }
});
