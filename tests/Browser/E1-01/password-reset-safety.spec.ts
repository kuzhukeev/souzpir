import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const base = process.env.E101G_BROWSER_BASE || 'http://127.0.0.1:8241';
const php = process.env.PHP_BINARY || 'C:/OSPanel/modules/PHP-8.4/php.exe';
const email = 'recovery-browser@example.test';
test.use({ baseURL: base, trace: 'off', launchOptions: process.env.PLAYWRIGHT_CHROME ? { executablePath: process.env.PLAYWRIGHT_CHROME } : {} });

test('recovery keeps credentials out of URLs, history and browser storage', async ({ page }) => {
  const token = execFileSync(php, ['-d', 'sys_temp_dir=.local/tmp', 'tests/Browser/E1-01/password-reset-fixture.php', 'setup'], {
    cwd: process.cwd(), env: { ...process.env, APP_ENV: 'testing', E101G_BROWSER_FIXTURE: '1', ...(process.env.CI === 'true' ? { E101G_CI_FIXTURE: '1', DB_DATABASE: 'souzpir_test_sp_e1_01g', DB_TEST_DATABASE: 'souzpir_test_sp_e1_01g', DB_USERNAME: 'sp_e101g_app', APP_URL: base, APP_STORAGE_PATH: `${process.cwd()}/.local/runtime/testing/storage/e101g` } : {}) }, encoding: 'utf8', timeout: 60000,
  }).trim();
  expect(token).toMatch(/^[a-f0-9]{64}$/);
  const urls: string[] = [];
  let resetBody = '';
  let exchangeBody = '';
  await page.route('**/*', async route => {
    const request = route.request();
    urls.push(request.url());
    if (new URL(request.url()).origin !== new URL(base).origin) return route.abort();
    expect(request.url()).not.toContain(token);
    expect(request.url()).not.toContain(encodeURIComponent(email));
    expect(request.url()).not.toContain(email);
    if (request.method() === 'POST' && new URL(request.url()).pathname === '/reset-password') resetBody = request.postData() || '';
    if (request.method() === 'POST' && new URL(request.url()).pathname === '/reset-password/exchange') exchangeBody = request.postData() || '';
    await route.continue();
  });
  await page.goto(`/reset-password/open#token=${token}&email=${encodeURIComponent(email)}`);
  await expect(page).toHaveURL(/\/reset-password$/);
  await expect(page.getByLabel('Новый пароль')).toBeVisible();
  expect(await page.evaluate(() => location.hash)).toBe('');
  const saved = await page.evaluate(() => JSON.stringify([history.state, { ...sessionStorage }, { ...localStorage }]));
  expect(saved).not.toContain(token);
  expect(saved).not.toContain(email);
  for (const [width, height] of [[1440, 900], [1024, 768], [390, 844]]) {
    await page.setViewportSize({ width, height });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    await page.screenshot({ path: `.local/screenshots/e1-01g-reset-${width}.png` });
  }
  for (const theme of ['neutral', 'confectionery', 'pyro']) {
    await page.evaluate(value => { document.documentElement.dataset.theme = value; }, theme);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    await page.screenshot({ path: `.local/screenshots/e1-01g-reset-390-${theme}.png` });
  }
  await page.getByLabel('Новый пароль').fill('new-synthetic-password');
  await page.getByLabel('Повторите пароль').fill('new-synthetic-password');
  await page.getByRole('button', { name: 'Сохранить пароль' }).click();
  await expect(page).toHaveURL(/\/login$/);
  expect(exchangeBody).toContain(token);
  expect(exchangeBody).toContain(email);
  expect(resetBody).not.toContain(token);
  expect(resetBody).not.toContain(email);
  await page.goBack();
  expect(page.url()).not.toContain(token);
  expect(page.url()).not.toContain(email);
  expect(urls.every(url => !url.includes(token) && !url.includes(email))).toBeTruthy();
  if (process.env.E101G_APACHE_LOG_DIR) {
    const access = readFileSync(`${process.env.E101G_APACHE_LOG_DIR}/access.log`, 'utf8');
    const errors = readFileSync(`${process.env.E101G_APACHE_LOG_DIR}/error.log`, 'utf8');
    expect(access).toContain('GET /reset-password/open ');
    expect(access).toContain('POST /reset-password/exchange ');
    expect(access).toContain('GET /__proxy_error ');
    expect(errors).toContain('AH01114');
    for (const log of [access, errors, readFileSync('.local/runtime/testing/storage/logs/laravel.log', 'utf8')]) {
      expect(log).not.toContain(token);
      expect(log).not.toContain(email);
      expect(log).not.toContain(encodeURIComponent(email));
    }
  }
});

test('a newer fragment wins while an exchange is in flight', async ({ page }) => {
  const token = execFileSync(php, ['-d', 'sys_temp_dir=.local/tmp', 'tests/Browser/E1-01/password-reset-fixture.php', 'setup'], {
    cwd: process.cwd(), env: { ...process.env, APP_ENV: 'testing', E101G_BROWSER_FIXTURE: '1', ...(process.env.CI === 'true' ? { E101G_CI_FIXTURE: '1', DB_DATABASE: 'souzpir_test_sp_e1_01g', DB_TEST_DATABASE: 'souzpir_test_sp_e1_01g', DB_USERNAME: 'sp_e101g_app', APP_URL: base, APP_STORAGE_PATH: `${process.cwd()}/.local/runtime/testing/storage/e101g` } : {}) }, encoding: 'utf8', timeout: 60000,
  }).trim();
  const previous = 'b'.repeat(64);
  let releaseFirst = () => {};
  let noteFirst = () => {};
  const firstPaused = new Promise<void>(resolve => { noteFirst = resolve; });
  const continueFirst = new Promise<void>(resolve => { releaseFirst = resolve; });
  let exchanges = 0;
  const urls: string[] = [];
  await page.route('**/*', async route => {
    const request = route.request();
    urls.push(request.url());
    if (new URL(request.url()).origin !== new URL(base).origin) return route.abort();
    if (new URL(request.url()).pathname === '/reset-password/exchange' && ++exchanges === 1) {
      noteFirst();
      await continueFirst;
    }
    await route.continue();
  });
  await page.goto(`/reset-password/open#token=${previous}&email=${encodeURIComponent(email)}`);
  await firstPaused;
  await page.evaluate(({ nextToken, nextEmail }) => { location.hash = `token=${nextToken}&email=${encodeURIComponent(nextEmail)}`; }, { nextToken: token, nextEmail: email });
  releaseFirst();
  await expect(page).toHaveURL(/\/reset-password$/);
  expect(exchanges).toBe(2);
  expect(await page.evaluate(() => JSON.stringify(history.state))).not.toContain(token);
  await page.getByLabel('Новый пароль').fill('new-synthetic-password');
  await page.getByLabel('Повторите пароль').fill('new-synthetic-password');
  await page.getByRole('button', { name: 'Сохранить пароль' }).click();
  await expect(page).toHaveURL(/\/login$/);
  expect(urls.every(url => !url.includes(token) && !url.includes(previous) && !url.includes(email))).toBeTruthy();
});

test('invalid fragment is removed and offers a new link', async ({ page }) => {
  await page.goto('/reset-password/open#invalid-synthetic-fragment');
  await expect(page.getByRole('link', { name: 'Запросить новую ссылку' })).toBeVisible();
  expect(await page.evaluate(() => location.hash)).toBe('');
  expect(JSON.stringify(await page.evaluate(() => history.state))).not.toContain('invalid-synthetic-fragment');
});
