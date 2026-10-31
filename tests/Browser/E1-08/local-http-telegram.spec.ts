import { expect, test } from '@playwright/test';

const base = process.env.PROFILE_LOCAL_HTTP_URL || 'http://souzpir.local:8248';
test.use({
  trace: 'off',
  launchOptions: { executablePath: process.env.PROFILE_CHROMIUM_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe' },
});

test('the local HTTP cabinet can prepare a Telegram link without exposing it in Inertia history', async ({ page, context }) => {
  await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort('blockedbyclient'));
  await page.goto(base + '/login');
  await page.locator('input[type=email]').fill('profile-e108@example.test');
  await page.locator('input[type=password]').fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).not.toHaveURL(/\/login$/);
  await page.goto(base + '/profile/notifications');
  expect(await page.evaluate(() => Boolean(globalThis.crypto?.subtle))).toBe(false);
  await page.getByRole('button', { name: 'Подключить Telegram' }).click();
  const link = page.getByRole('link', { name: 'Открыть Telegram' });
  await expect(link).toBeVisible();
  await expect(link).toHaveAttribute('href', '/profile/notifications/telegram/link');
  const response = await context.request.get(base + '/profile/notifications/telegram/link', { maxRedirects: 0 });
  expect(response.status()).toBe(302);
  expect(response.headers()['cache-control']).toContain('no-store');
  const destination = new URL(response.headers()['location']);
  expect(destination.origin).toBe('https://t.me');
  const token = destination.searchParams.get('start');
  expect(Boolean(token && /^[a-f0-9]{48}$/.test(token))).toBe(true);
  const historySnapshot = await page.evaluate(() => JSON.stringify([window.history.state, { ...sessionStorage }, { ...localStorage }]));
  expect(historySnapshot.includes(token!)).toBe(false);
  expect((await page.locator('body').innerText()).includes(token!)).toBe(false);
  await page.getByRole('button', { name: 'Сохранить изменения' }).click();
  await expect(page.getByRole('status')).toContainText('Изменения сохранены');
  await page.screenshot({ path: '.local/screenshots/e1-08/local-http-telegram.png', fullPage: true });
});
