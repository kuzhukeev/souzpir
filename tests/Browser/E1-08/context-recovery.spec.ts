import { expect, test } from '@playwright/test';

const fixture = 'http://127.0.0.1:5238/tests/Browser/E1-08/ui-fixture.html';
test.use({ launchOptions: process.env.PROFILE_CHROMIUM_PATH ? { executablePath: process.env.PROFILE_CHROMIUM_PATH } : {} });

test('logout keeps its key across reload and reads the revoked receipt', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(fixture + '?screen=ready');
  const key = 'logout-browser-recovery-123456';
  await page.evaluate((value) => {
    sessionStorage.setItem('souzpir.profile-logout.retry', JSON.stringify({ key: value, xsrf: '' }));
    localStorage.setItem('souzpir.profile-context.pending.' + value, '1');
  }, key);
  await page.route('**/logout/recover', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: '{"logged_out":true}' }));
  await page.route('**/login', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<html lang="ru"><body>Вход</body></html>' }));
  await page.goto(fixture + '?screen=ready');
  await expect(page.getByRole('button', { name: 'Повторить выход' })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/e1-08c/logout-retry-1440.png', fullPage: true });
  const request = page.waitForRequest('**/logout/recover');
  await page.getByRole('button', { name: 'Повторить выход' }).click();
  expect((await request).headers()['x-context-change-key']).toBe(key);
  await expect(page).toHaveURL('http://127.0.0.1:5238/login');
  expect(await page.evaluate((value) => localStorage.getItem('souzpir.profile-context.pending.' + value), key)).toBeNull();
});

test('brand switch offers its original request after reload', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(fixture + '?screen=switch');
  const key = 'brand-browser-recovery-123456';
  await page.evaluate((value) => {
    localStorage.setItem('souzpir.profile-brand-switch.retry', JSON.stringify({ id: '01J00000000000000000000005', from: '01J00000000000000000000004', key: value }));
    localStorage.setItem('souzpir.profile-context.pending.' + value, '1');
  }, key);
  await page.route('**/brand/switch', (route) => route.fulfill({ status: 401, contentType: 'application/json', body: '{}' }));
  await page.route('**/brand/switch/recover?**', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: '{"switched":true}' }));
  await page.route('**/login', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<html lang="ru"><body>Вход</body></html>' }));
  await page.goto(fixture + '?screen=switch');
  await expect(page.getByRole('button', { name: 'Повторить' })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/e1-08c/brand-retry-1440.png', fullPage: true });
  const request = page.waitForRequest('**/brand/switch');
  const recovery = page.waitForRequest('**/brand/switch/recover?**');
  await page.getByRole('button', { name: 'Повторить' }).click();
  expect((await request).headers()['x-context-change-key']).toBe(key);
  expect((await recovery).headers()['x-context-change-key']).toBe(key);
  await expect(page).toHaveURL('http://127.0.0.1:5238/login');
  expect(await page.evaluate((value) => localStorage.getItem('souzpir.profile-context.pending.' + value), key)).toBeNull();
});
