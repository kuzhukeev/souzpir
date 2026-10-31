import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';

test.skip(
  process.env.RUN_BILLING_E2E !== '1',
  'Requires the isolated SP-E1-07 test database and server.',
);
const marker = 'E107-PRIVATE-PAYER-MARKER';
const emailMarker = 'e107-private-payer@example.test';
type Fixture = { owner_email: string; admin_email: string };
let fixture: Fixture;

async function isolatedContext(browser: Browser): Promise<BrowserContext> {
  const context = await browser.newContext({ baseURL: 'http://127.0.0.1:8107' });
  await context.route('**/*', (route) =>
    new URL(route.request().url()).origin === 'http://127.0.0.1:8107'
      ? route.continue()
      : route.abort(),
  );
  await context.addInitScript(
    ({ marker, emailMarker }) => {
      // Exercise Inertia's plaintext-history fallback even though loopback is a secure context.
      Object.defineProperty(window.crypto, 'subtle', { value: undefined, configurable: true });
      const check = (state: unknown) => {
        const serialized = JSON.stringify(state) ?? '';
        if (serialized.includes(marker) || serialized.includes(emailMarker))
          sessionStorage.setItem('e107-history-leak', 'yes');
      };
      for (const key of ['pushState', 'replaceState'] as const) {
        const original = history[key].bind(history);
        history[key] = function (state: unknown, unused: string, url?: string | URL | null) {
          check(state);
          return original(state, unused, url);
        };
      }
      check(history.state);
      window.addEventListener('popstate', () => check(history.state));
      window.addEventListener('pageshow', () => check(history.state));
    },
    { marker, emailMarker },
  );
  return context;
}

async function login(page: Page, email: string) {
  await page.goto('/login');
  await page.getByLabel('Рабочая почта').fill(email);
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/\/auth\/ready$/);
}

async function noHistoryLeak(page: Page) {
  const state = await page.evaluate(() => ({
    history: JSON.stringify(history.state) ?? '',
    leak: sessionStorage.getItem('e107-history-leak'),
    subtle: typeof crypto.subtle,
  }));
  expect(state.subtle).toBe('undefined');
  expect(state.history).not.toContain(marker);
  expect(state.history).not.toContain(emailMarker);
  expect(state.leak).toBeNull();
}

async function noPayerVisible(page: Page) {
  await expect(page.locator('body')).not.toContainText(marker);
  await expect(page.locator('body')).not.toContainText(emailMarker);
  await expect
    .poll(async () =>
      page
        .locator('input, textarea')
        .evaluateAll((nodes) => nodes.map((node) => (node as HTMLInputElement).value).join('\n')),
    )
    .not.toContain(marker);
  await expect
    .poll(async () =>
      page
        .locator('input, textarea')
        .evaluateAll((nodes) => nodes.map((node) => (node as HTMLInputElement).value).join('\n')),
    )
    .not.toContain(emailMarker);
}

async function preparePayer(page: Page) {
  await page.goto('/billing/payer');
  await expect(page.getByLabel('Юридическое название')).toBeVisible();
  await page.getByLabel('Юридическое название').fill(marker);
  await page.getByLabel('ИНН', { exact: true }).fill('1234567890');
  await page.getByLabel('Почта для счетов').fill(emailMarker);
  const response = page.waitForResponse(
    (item) => item.url().endsWith('/billing/payer') && item.request().method() === 'PUT',
  );
  await page.getByRole('button', { name: 'Сохранить реквизиты' }).click();
  expect((await response).status()).toBe(200);
  await expect(page.getByRole('status')).toContainText('Реквизиты сохранены.');
  await noHistoryLeak(page);
}

function ownership(mode: 'transfer' | 'restore') {
  const env: NodeJS.ProcessEnv = {
    ...process.env,
    APP_ENV: 'testing',
    E107_FIXTURE_ENABLED: '1',
    E107_FIXTURE_ORG: '01J00000000000000000000000',
    SUPPORT_MAIL_ENABLED: 'false',
    SUPPORT_SMTP_ENABLED: 'false',
    NOTIFICATIONS_EXTERNAL_ENABLED: 'false',
    MAIL_MAILER: 'log',
  };
  delete env.SUPPORT_RECIPIENT;
  execFileSync(
    'C:/OSPanel/modules/PHP-8.4/php.exe',
    [
      '-d',
      `sys_temp_dir=${process.cwd()}/.local/tmp`,
      'tests/Browser/E1-07/transfer-fixture-owner.php',
      mode,
    ],
    { encoding: 'utf8', env },
  );
}

test.beforeAll(async () => {
  fixture = JSON.parse(await readFile('.local/runtime/e1-07-browser.json', 'utf8')) as Fixture;
});

test('payer stays out of plaintext history across SPA navigation, logout and another login', async ({
  browser,
}) => {
  const context = await isolatedContext(browser);
  try {
    const page = await context.newPage();
    await login(page, fixture.owner_email);
    await preparePayer(page);
    await page.getByRole('link', { name: 'Использование', exact: true }).click();
    await expect(page).toHaveURL(/\/billing\/usage$/);
    await noHistoryLeak(page);
    await page.goBack();
    await expect(page.getByLabel('Юридическое название')).toHaveValue(marker);
    await noHistoryLeak(page);
    await page.goForward();
    await expect(page).toHaveURL(/\/billing\/usage$/);
    await noHistoryLeak(page);
    await page.goBack();
    await expect(page.getByLabel('Юридическое название')).toHaveValue(marker);
    await noHistoryLeak(page);

    const logout = await context.newPage();
    await logout.goto('/auth/ready');
    await logout.getByRole('button', { name: 'Выйти', exact: true }).click();
    await expect(logout).toHaveURL(/\/login$/);
    let release!: () => void;
    const hold = new Promise<void>((resolve) => {
      release = resolve;
      setTimeout(resolve, 5000).unref();
    });
    await page.route('**/billing/payer/data', async (route) => {
      await hold;
      await route.continue();
    });
    const pending = page.waitForRequest(
      (item) => item.url().endsWith('/billing/payer/data') && item.method() === 'GET',
      { timeout: 5000 },
    );
    await page.bringToFront();
    await page.evaluate(() =>
      window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true })),
    );
    await noPayerVisible(page);
    await page.evaluate(() =>
      window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })),
    );
    await pending;
    await noPayerVisible(page);
    const denied = page.waitForResponse(
      (item) => item.url().endsWith('/billing/payer/data') && item.request().method() === 'GET',
      { timeout: 8000 },
    );
    release();
    expect((await denied).status()).toBe(401);
    await noPayerVisible(page);
    await page.unroute('**/billing/payer/data');
    const anonymous = await page.request.get('/billing/payer/data', {
      headers: { Accept: 'application/json' },
    });
    expect(anonymous.status()).toBe(401);
    await page.goto('/login');
    await noPayerVisible(page);
    await login(page, fixture.admin_email);
    await page.goBack();
    await noPayerVisible(page);
    const admin = await page.request.get('/billing/payer/data', {
      headers: { Accept: 'application/json' },
    });
    expect(admin.status()).toBe(403);
    await noHistoryLeak(page);
  } finally {
    await context.close();
  }
});

test('ownership change hides restored payer before denied fetch and reauthorizes on return', async ({
  browser,
}) => {
  const context = await isolatedContext(browser);
  let transferred = false;
  try {
    const page = await context.newPage();
    await login(page, fixture.owner_email);
    await preparePayer(page);
    await page.getByRole('link', { name: 'Использование', exact: true }).click();
    await expect(page).toHaveURL(/\/billing\/usage$/);
    ownership('transfer');
    transferred = true;
    let release!: () => void;
    const hold = new Promise<void>((resolve) => {
      release = resolve;
      setTimeout(resolve, 5000).unref();
    });
    await page.route('**/billing/payer/data', async (route) => {
      await hold;
      await route.continue();
    });
    const request = page.waitForRequest(
      (item) => item.url().endsWith('/billing/payer/data') && item.method() === 'GET',
    );
    const result = page.waitForResponse(
      (item) => item.url().endsWith('/billing/payer/data') && item.request().method() === 'GET',
    );
    const back = page.goBack();
    await request;
    await noPayerVisible(page);
    release();
    await back;
    expect((await result).status()).toBe(403);
    await noPayerVisible(page);
    await noHistoryLeak(page);
    await page.unroute('**/billing/payer/data');
    ownership('restore');
    transferred = false;
    const fresh = page.waitForResponse(
      (item) => item.url().endsWith('/billing/payer/data') && item.request().method() === 'GET',
    );
    await page.reload();
    expect((await fresh).status()).toBe(200);
    await expect(page.getByLabel('Юридическое название')).toHaveValue(marker);
    await noHistoryLeak(page);
    ownership('transfer');
    transferred = true;
    let releaseRestored!: () => void;
    const holdRestored = new Promise<void>((resolve) => {
      releaseRestored = resolve;
      setTimeout(resolve, 5000).unref();
    });
    await page.route('**/billing/payer/data', async (route) => {
      await holdRestored;
      await route.continue();
    });
    const restoredRequest = page.waitForRequest(
      (item) => item.url().endsWith('/billing/payer/data') && item.method() === 'GET',
    );
    await page.evaluate(() =>
      window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true })),
    );
    await noPayerVisible(page);
    await page.evaluate(() =>
      window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })),
    );
    await restoredRequest;
    await noPayerVisible(page);
    const denied = page.waitForResponse(
      (item) => item.url().endsWith('/billing/payer/data') && item.request().method() === 'GET',
    );
    releaseRestored();
    expect((await denied).status()).toBe(403);
    await noPayerVisible(page);
    await noHistoryLeak(page);
    await page.unroute('**/billing/payer/data');
    await page.goto('/auth/ready');
    const backAfterFullNavigation = await page.goBack({ waitUntil: 'domcontentloaded' });
    if (backAfterFullNavigation) expect(backAfterFullNavigation.status()).toBe(403);
    await noPayerVisible(page);
    await noHistoryLeak(page);
  } finally {
    if (transferred) ownership('restore');
    await context.close();
  }
});
