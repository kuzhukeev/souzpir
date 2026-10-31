import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';

test.use({ trace: 'off', launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe' } });
const base = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8244';
const php = process.env.PHP_BINARY || 'C:/OSPanel/modules/PHP-8.4/php.exe';
const fixture = (action: string) => execFileSync(php, ['-d', 'sys_temp_dir=.local/tmp', 'tests/Browser/E1-01/invitation-fixture.php', action], {
  cwd: process.cwd(), env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8', timeout: 60000,
}).trim();

test.beforeEach(() => { expect(fixture('setup')).toBe('ready'); });

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

test('a new invitation works in the same tab after a revoked link', async ({ page }) => {
  const revoked = 'R'.repeat(64);
  const valid = 'C'.repeat(64);
  const urls: string[] = [];
  page.on('request', request => urls.push(request.url()));
  await page.route('**/*', route => new URL(route.request().url()).origin === new URL(base).origin ? route.continue() : route.abort());
  await page.goto(`/invitations/open#${revoked}`);
  await expect(page.getByText('Не удалось открыть приглашение. Запросите новую ссылку.')).toBeVisible();
  expect(await page.evaluate(() => location.hash)).toBe('');
  await page.evaluate(token => { location.hash = token; }, valid);
  await expect(page).toHaveURL(/\/invitations\/show$/);
  await expect(page.getByText('retry-invite@example.test')).toBeVisible();
  expect(urls.every(url => !url.includes(revoked) && !url.includes(valid))).toBeTruthy();
  const saved = await page.evaluate(() => JSON.stringify([history.state, { ...sessionStorage }, { ...localStorage }]));
  expect(saved).not.toContain(revoked);
  expect(saved).not.toContain(valid);
  await page.goBack();
  expect(page.url()).not.toContain(revoked);
  expect(page.url()).not.toContain(valid);
});

test('two rapid fragment navigations do not create intermediate history entries in supported browsers', async ({ page }) => {
  const oldToken = 'D'.repeat(64);
  const newToken = 'E'.repeat(64);
  await page.goto(`/invitations/open#${'R'.repeat(64)}`);
  await expect(page.getByText('Не удалось открыть приглашение. Запросите новую ссылку.')).toBeVisible();
  const entries = await page.evaluate(([oldValue, newValue]) => {
    location.hash = oldValue;
    location.hash = newValue;
    const browser = window as unknown as Window & { navigation: { entries(): { url: string }[] } };
    return browser.navigation.entries().map(entry => entry.url);
  }, [oldToken, newToken]);
  expect(entries.every(url => !url.includes(oldToken) && !url.includes(newToken))).toBeTruthy();
  await expect(page).toHaveURL(/\/invitations\/show$/);
  await expect(page.getByText('newer-invite@example.test')).toBeVisible();
});

test('same-tab exchanges are serialized and the latest invitation wins', async ({ page }) => {
  const older = 'D'.repeat(64);
  const newer = 'E'.repeat(64);
  const requests: string[] = [];
  const urls: string[] = [];
  let firstCommitted!: () => void;
  const committed = new Promise<void>(resolve => { firstCommitted = resolve; });
  let releaseFirst!: () => void;
  const release = new Promise<void>(resolve => { releaseFirst = resolve; });
  page.on('request', request => urls.push(request.url()));
  await page.route('**/*', async route => {
    const request = route.request();
    if (new URL(request.url()).origin !== new URL(base).origin) return route.abort();
    if (!request.url().endsWith('/invitations/exchange')) {
      expect(request.postData() || '').not.toContain(older);
      expect(request.postData() || '').not.toContain(newer);
      return route.continue();
    }
    const token = JSON.parse(request.postData() || '{}').token as string;
    requests.push(token);
    if (token === older) {
      // The old exchange has already committed its session; delay only its response.
      const response = await route.fetch();
      expect(response.status()).toBe(200);
      firstCommitted();
      await release;
      return route.fulfill({ response });
    }
    return route.continue();
  });
  try {
    await page.goto(`/invitations/open#${older}`);
    await committed;
    expect(await page.evaluate(() => location.hash)).toBe('');
    await page.evaluate(async token => { location.hash = token; await new Promise(resolve => setTimeout(resolve, 0)); }, newer);
    expect(await page.evaluate(() => location.hash)).toBe('');
    await page.waitForTimeout(250);
    expect(requests).toEqual([older]);
    expect(new URL(page.url()).pathname).toBe('/invitations/open');
  } finally {
    releaseFirst();
  }
  await expect(page).toHaveURL(/\/invitations\/show$/);
  expect(requests).toEqual([older, newer]);
  await expect(page.getByText('newer-invite@example.test')).toBeVisible();
  expect(urls.every(url => !url.includes(older) && !url.includes(newer))).toBeTruthy();
  const saved = await page.evaluate(() => JSON.stringify([history.state, { ...sessionStorage }, { ...localStorage }]));
  expect(saved).not.toContain(older);
  expect(saved).not.toContain(newer);
});

test('a malformed newer link clears the previous invitation grant', async ({ page }) => {
  const older = 'D'.repeat(64);
  const invalid = 'invalid-secret-fragment!';
  let firstCommitted!: () => void;
  const committed = new Promise<void>(resolve => { firstCommitted = resolve; });
  let releaseFirst!: () => void;
  const release = new Promise<void>(resolve => { releaseFirst = resolve; });
  const exchanged: string[] = [];
  await page.route('**/*', async route => {
    const request = route.request();
    if (new URL(request.url()).origin !== new URL(base).origin) return route.abort();
    if (!request.url().endsWith('/invitations/exchange')) return route.continue();
    const token = JSON.parse(request.postData() || '{}').token as string;
    exchanged.push(token);
    if (token === older) {
      const response = await route.fetch();
      firstCommitted();
      await release;
      return route.fulfill({ response });
    }
    return route.continue();
  });
  try {
    await page.goto(`/invitations/open#${older}`);
    await committed;
    await page.evaluate(async token => { location.hash = token; await new Promise(resolve => setTimeout(resolve, 0)); }, invalid);
    expect(await page.evaluate(() => location.hash)).toBe('');
  } finally {
    releaseFirst();
  }
  await expect(page.getByText('Не удалось открыть приглашение. Запросите новую ссылку.')).toBeVisible();
  expect(exchanged).toEqual([older, '']);
  expect((await page.request.get(`${base}/invitations/show`)).status()).toBe(404);
});

test('a lost exchange response does not start a newer potentially racing exchange', async ({ page }) => {
  const older = 'D'.repeat(64);
  const newer = 'E'.repeat(64);
  let firstCommitted!: () => void;
  const committed = new Promise<void>(resolve => { firstCommitted = resolve; });
  let releaseFirst!: () => void;
  const release = new Promise<void>(resolve => { releaseFirst = resolve; });
  const exchanged: string[] = [];
  await page.route('**/*', async route => {
    const request = route.request();
    if (new URL(request.url()).origin !== new URL(base).origin) return route.abort();
    if (!request.url().endsWith('/invitations/exchange')) return route.continue();
    const token = JSON.parse(request.postData() || '{}').token as string;
    exchanged.push(token);
    if (token === older) {
      await route.fetch();
      firstCommitted();
      await release;
      return route.abort('failed');
    }
    return route.continue();
  });
  try {
    await page.goto(`/invitations/open#${older}`);
    await committed;
    await page.evaluate(async token => { location.hash = token; await new Promise(resolve => setTimeout(resolve, 0)); }, newer);
    expect(await page.evaluate(() => location.hash)).toBe('');
  } finally {
    releaseFirst();
  }
  await expect(page.getByText('Не удалось открыть приглашение. Запросите новую ссылку.')).toBeVisible();
  expect(exchanged).toEqual([older]);
  expect(new URL(page.url()).pathname).toBe('/invitations/open');
});
