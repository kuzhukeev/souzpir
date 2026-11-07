import { expect, test } from '@playwright/test';
import { createHmac } from 'node:crypto';
import { mkdirSync } from 'node:fs';

const port = process.env.MFA_LOCAL_PORT || '8293';
const base = `http://souzpir.local:${port}`;
test.use({ launchOptions: { executablePath: process.env.MFA_BROWSER_EXECUTABLE_PATH || undefined, args: ['--host-resolver-rules=MAP souzpir.local 127.0.0.1', '--no-proxy-server'] }, trace: 'off' });
function totp() {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const digest = createHmac('sha1', Buffer.from('12345678901234567890')).update(counter).digest();
  return ((digest.readUInt32BE(digest[digest.length - 1] & 15) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
}
test('local profile data stay outside Inertia history and are revalidated after logout', async ({ page, context }) => {
  await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort('blockedbyclient'));
  await page.goto(base + '/login');
  await page.locator('input[type=email]').fill('mfa-recovery@example.test');
  await page.locator('input[type=password]').fill('fixture-only-password');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await page.waitForURL('**/mfa/challenge');
  await page.getByLabel('Код подтверждения').fill(totp());
  await page.getByRole('button', { name: 'Подтвердить' }).click();
  await page.waitForURL(url => !url.pathname.includes('/mfa/'));
  const responses: { path: string; cache: string }[] = [];
  page.on('response', response => {
    const url = new URL(response.url());
    if (url.pathname.startsWith('/profile/local-data/')) responses.push({ path: url.pathname, cache: response.headers()['cache-control'] || '' });
  });
  await page.goto(base + '/billing');
  await page.getByRole('link', { name: 'Настройки', exact: true }).click();
  await expect(page.getByText('События и каналы', { exact: true })).toBeVisible();
  expect(await page.evaluate(() => JSON.stringify(history.state))).not.toContain('mfa-recovery@example.test');
  await page.getByRole('navigation', { name: 'Личные настройки' }).getByRole('link', { name: 'Профиль', exact: true }).click();
  await expect(page.getByLabel('Электронная почта')).toHaveValue('mfa-recovery@example.test');
  expect(await page.evaluate(() => Boolean(crypto.subtle))).toBe(false);
  const noPersonalHistory = async () => {
    const state = await page.evaluate(() => JSON.stringify([history.state, { ...sessionStorage }, { ...localStorage }]));
    expect(state).not.toContain('mfa-recovery@example.test');
    expect(state).not.toContain('Синтетический владелец');
    expect(state).not.toContain('Тестовое имя после сохранения');
    expect(state).not.toContain('fixture-only-password');
  };
  await noPersonalHistory();
  await page.getByLabel('Имя', { exact: true }).fill('Тестовое имя после сохранения');
  await Promise.all([
    page.waitForResponse(response => new URL(response.url()).pathname === '/profile' && response.request().method() === 'PUT' && response.status() === 302),
    page.getByRole('button', { name: 'Сохранить изменения' }).click(),
  ]);
  await page.reload();
  await expect(page.getByLabel('Имя', { exact: true })).toHaveValue('Тестовое имя после сохранения');
  expect(await page.evaluate(() => JSON.stringify(history.state))).not.toContain('Тестовое имя после сохранения');
  mkdirSync('.local/screenshots', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/local-profile-synthetic.png', fullPage: true });
  await page.getByRole('navigation', { name: 'Личные настройки' }).getByRole('link', { name: 'Безопасность' }).click();
  await expect(page.getByText('Текущая сессия', { exact: false }).first()).toBeVisible();
  await noPersonalHistory();
  await page.getByRole('navigation', { name: 'Личные настройки' }).getByRole('link', { name: 'Уведомления' }).click();
  await expect(page.getByText('События и каналы', { exact: true })).toBeVisible();
  const toggle = page.getByRole('switch').first();
  const original = await toggle.getAttribute('aria-checked');
  await toggle.click();
  const updated = original === 'true' ? 'false' : 'true';
  await Promise.all([
    page.waitForResponse(response => new URL(response.url()).pathname === '/profile/notifications' && response.request().method() === 'PUT' && response.status() === 302),
    page.getByRole('button', { name: 'Сохранить изменения' }).click(),
  ]);
  await page.reload();
  await expect(page.getByRole('switch').first()).toHaveAttribute('aria-checked', updated);
  await noPersonalHistory();
  await page.goBack();
  await expect(page.getByText('Текущая сессия', { exact: false }).first()).toBeVisible();
  await noPersonalHistory();
  await page.goBack();
  await expect(page.getByLabel('Электронная почта')).toHaveValue('mfa-recovery@example.test');
  await noPersonalHistory();
  expect(responses.map(item => item.path)).toEqual(expect.arrayContaining(['/profile/local-data/profile', '/profile/local-data/security', '/profile/local-data/notifications']));
  expect(responses.every(item => item.cache.includes('no-store'))).toBe(true);

  const other = await context.newPage();
  await other.goto(base + '/billing');
  await other.bringToFront();
  await other.evaluate(async () => {
    const cookie = document.cookie.split('; ').find(item => item.startsWith('XSRF-TOKEN='));
    if (!cookie) throw new Error('Missing CSRF cookie');
    const response = await fetch('/logout', { method: 'POST', headers: { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) }, credentials: 'same-origin' });
    if (!response.ok) throw new Error('Logout failed');
  });
  expect(await other.evaluate(async () => (await fetch('/profile/local-data/profile', { headers: { Accept: 'application/json' }, credentials: 'same-origin', redirect: 'manual' })).status)).toBe(401);
  await page.bringToFront();
  await expect(page.getByLabel('Электронная почта')).toHaveCount(0);
  await noPersonalHistory();
});
