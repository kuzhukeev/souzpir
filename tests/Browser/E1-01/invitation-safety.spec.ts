import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';

test.use({ trace: 'off', launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe' } });
const base = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8244';
const php = process.env.PHP_BINARY || 'C:/OSPanel/modules/PHP-8.4/php.exe';
const fixture = (action: string) => execFileSync(php, ['-d', 'sys_temp_dir=.local/tmp', 'tests/Browser/E1-01/invitation-fixture.php', action], {
  cwd: process.cwd(), env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8', timeout: 60000,
}).trim();

test.beforeAll(() => { expect(fixture('setup')).toBe('ready'); });

test('new user: fragment exchange, registration and verified acceptance', async ({ page }) => {
  const token = 'A'.repeat(64);
  const seen: string[] = [];
  let exchangeBody = '';
  await page.route('**/*', async route => {
    const request = route.request();
    const url = request.url();
    seen.push(url);
    if (new URL(url).origin !== new URL(base).origin) return route.abort();
    if (url.endsWith('/invitations/exchange')) {
      exchangeBody = request.postData() || '';
      expect(request.headers()['x-csrf-token']).toBeTruthy();
    } else {
      expect(request.postData() || '').not.toContain(token);
    }
    await route.continue();
  });
  await page.goto(`/invitations/open#${token}`);
  await expect(page).toHaveURL(/\/invitations\/show$/);
  expect(exchangeBody).toContain(token);
  expect(await page.evaluate(() => location.hash)).toBe('');
  expect((await page.locator('body').innerText())).not.toContain(token);
  await page.getByLabel('Имя').fill('New Invited');
  await page.getByLabel('Пароль', { exact: true }).fill('another-secure-password');
  await page.getByLabel('Повторите пароль').fill('another-secure-password');
  await page.getByLabel('Я принимаю условия использования и обработки данных').check();
  await page.getByRole('button', { name: 'Создать аккаунт' }).click();
  await expect(page).toHaveURL(/\/email\/verify$/);
  const verification = fixture('verification-url');
  await page.goto(verification);
  await page.getByRole('button', { name: 'Подтвердить адрес' }).click();
  await expect(page).toHaveURL(/\/invitations\/show$/);
  await page.getByRole('button', { name: 'Принять приглашение' }).click();
  await expect(page).toHaveURL(/\/auth\/ready$/);
  expect(seen.every(url => !url.includes(token))).toBeTruthy();
  expect(seen.every(url => new URL(url).origin === new URL(base).origin)).toBeTruthy();
});

test('existing user: login retains session grant; invalid fragment shows generic error', async ({ page }) => {
  const token = 'B'.repeat(64);
  await page.route('**/*', async route => {
    const request = route.request();
    expect(request.url()).not.toContain(token);
    if (new URL(request.url()).origin !== new URL(base).origin) return route.abort();
    if (!request.url().endsWith('/invitations/exchange')) expect(request.postData() || '').not.toContain(token);
    await route.continue();
  });
  await page.goto(`/invitations/open#${token}`);
  await expect(page).toHaveURL(/\/invitations\/show$/);
  await page.getByRole('link', { name: 'Войти' }).click();
  await page.getByLabel('Рабочая почта').fill('existing-invite@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/\/invitations\/show$/);
  await page.getByRole('button', { name: 'Принять приглашение' }).click();
  await expect(page).toHaveURL(/\/auth\/ready$/);
  await page.goto(`/invitations/open#${'Z'.repeat(64)}`);
  await expect(page.getByText('Не удалось открыть приглашение. Запросите новую ссылку.')).toBeVisible();
  expect(await page.evaluate(() => location.hash)).toBe('');
});
