import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';

test.skip(
  process.env.RUN_BILLING_E2E !== '1',
  'Requires the isolated SP-E1-07 test database and server.',
);
const marker = 'E107-PRIVATE-PAYER-MARKER';
const emailMarker = 'e107-private-payer@example.test';
const draftMarker = 'E107-UNSAVED-DRAFT-MARKER';
type Fixture = { owner_email: string; admin_email: string };
let fixture: Fixture;

async function isolatedContext(browser: Browser): Promise<BrowserContext> {
  const context = await browser.newContext({
    baseURL: 'https://127.0.0.1:8107',
    ignoreHTTPSErrors: true,
  });
  await context.route('**/*', (route) =>
    new URL(route.request().url()).origin === 'https://127.0.0.1:8107'
      ? route.continue()
      : route.abort(),
  );
  await context.addInitScript(
    ({ marker, emailMarker, draftMarker }) => {
      // Exercise Inertia's plaintext-history fallback even though loopback is a secure context.
      Object.defineProperty(window.crypto, 'subtle', { value: undefined, configurable: true });
      const check = (state: unknown) => {
        const serialized = JSON.stringify(state) ?? '';
        if (
          serialized.includes(marker) ||
          serialized.includes(emailMarker) ||
          serialized.includes(draftMarker)
        )
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
    { marker, emailMarker, draftMarker },
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
    local: JSON.stringify(localStorage),
    session: JSON.stringify(sessionStorage),
    subtle: typeof crypto.subtle,
  }));
  expect(state.subtle).toBe('undefined');
  expect(state.history).not.toContain(marker);
  expect(state.history).not.toContain(emailMarker);
  expect(state.history).not.toContain(draftMarker);
  expect(state.local).not.toContain(marker);
  expect(state.local).not.toContain(emailMarker);
  expect(state.local).not.toContain(draftMarker);
  expect(state.session).not.toContain(marker);
  expect(state.session).not.toContain(emailMarker);
  expect(state.session).not.toContain(draftMarker);
  expect(state.leak).toBeNull();
}

async function noPayerVisible(page: Page) {
  await expect(page.locator('body')).not.toContainText(marker);
  await expect(page.locator('body')).not.toContainText(emailMarker);
  await expect(page.locator('body')).not.toContainText(draftMarker);
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
  await expect
    .poll(async () =>
      page
        .locator('input, textarea')
        .evaluateAll((nodes) => nodes.map((node) => (node as HTMLInputElement).value).join('\n')),
    )
    .not.toContain(draftMarker);
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

test('unsaved payer draft is hidden on blur and restored only after matching revision', async ({
  browser,
}) => {
  const context = await isolatedContext(browser);
  try {
    const page = await context.newPage();
    await login(page, fixture.owner_email);
    await page.goto('/billing/payer');
    await page.getByLabel('Юридическое название').fill(draftMarker);
    await noHistoryLeak(page);
    await page.evaluate(() => window.dispatchEvent(new Event('blur')));
    const other = await context.newPage();
    await other.goto('/auth/ready');
    await other.bringToFront();
    await noPayerVisible(page);
    await noHistoryLeak(page);
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
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await pending;
    await noPayerVisible(page);
    const response = page.waitForResponse(
      (item) => item.url().endsWith('/billing/payer/data') && item.request().method() === 'GET',
      { timeout: 8000 },
    );
    release();
    expect((await response).status()).toBe(200);
    await expect(page.getByLabel('Юридическое название')).toHaveValue(draftMarker);
    await noHistoryLeak(page);
    await page.unroute('**/billing/payer/data');
  } finally {
    await context.close();
  }
});

test('a changed server revision discards the hidden draft with a warning', async ({ browser }) => {
  const context = await isolatedContext(browser);
  try {
    const page = await context.newPage();
    await login(page, fixture.owner_email);
    await page.goto('/billing/payer');
    await page.getByLabel('Юридическое название').fill(draftMarker);
    await page.evaluate(() => window.dispatchEvent(new Event('blur')));
    const other = await context.newPage();
    await other.goto('/billing/payer');
    await other.bringToFront();
    await noPayerVisible(page);
    await other.getByLabel('Юридическое название').fill('E107-СЕРВЕРНАЯ-ВЕРСИЯ');
    const saved = other.waitForResponse(
      (item) => item.url().endsWith('/billing/payer') && item.request().method() === 'PUT',
    );
    await other.getByRole('button', { name: 'Сохранить реквизиты' }).click();
    expect((await saved).status()).toBe(200);
    await expect(other.getByRole('status')).toContainText('Реквизиты сохранены.');
    const fresh = page.waitForResponse(
      (item) => item.url().endsWith('/billing/payer/data') && item.request().method() === 'GET',
    );
    await page.bringToFront();
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    expect((await fresh).status()).toBe(200);
    await expect(page.getByLabel('Юридическое название')).toHaveValue('E107-СЕРВЕРНАЯ-ВЕРСИЯ');
    await expect(page.getByRole('alert')).toContainText('Несохранённые изменения удалены');
    await noHistoryLeak(page);
  } finally {
    await context.close();
  }
});

test('revoked payer access discards a hidden draft and explains the loss', async ({ browser }) => {
  const context = await isolatedContext(browser);
  let transferred = false;
  try {
    const page = await context.newPage();
    await login(page, fixture.owner_email);
    await page.goto('/billing/payer');
    await page.getByLabel('Юридическое название').fill(draftMarker);
    await page.evaluate(() => window.dispatchEvent(new Event('blur')));
    const other = await context.newPage();
    await other.goto('/auth/ready');
    await other.bringToFront();
    await noPayerVisible(page);
    ownership('transfer');
    transferred = true;
    const denied = page.waitForResponse(
      (item) => item.url().endsWith('/billing/payer/data') && item.request().method() === 'GET',
    );
    await page.bringToFront();
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    expect((await denied).status()).toBe(403);
    await noPayerVisible(page);
    await expect(page.getByRole('alert')).toContainText('Несохранённые изменения удалены');
    await noHistoryLeak(page);
  } finally {
    if (transferred) ownership('restore');
    await context.close();
  }
});

test('dirty payer draft warns before leaving and survives back only after reauthorization', async ({
  browser,
}) => {
  const context = await isolatedContext(browser);
  try {
    const page = await context.newPage();
    await login(page, fixture.owner_email);
    await page.goto('/billing/payer');
    await page.getByLabel('Юридическое название').fill(draftMarker);
    await noHistoryLeak(page);
    const refused = page.waitForEvent('dialog');
    const refusedClick = page.getByRole('link', { name: 'Использование', exact: true }).click();
    const refuseDialog = await refused;
    expect(refuseDialog.type()).toBe('confirm');
    expect(refuseDialog.message()).toContain('Есть несохранённые изменения');
    await refuseDialog.dismiss();
    await refusedClick;
    await expect(page).toHaveURL(/\/billing\/payer$/);
    await expect(page.getByLabel('Юридическое название')).toHaveValue(draftMarker);

    const accepted = page.waitForEvent('dialog');
    const acceptedClick = page.getByRole('link', { name: 'Использование', exact: true }).click();
    const acceptDialog = await accepted;
    expect(acceptDialog.type()).toBe('confirm');
    await acceptDialog.accept();
    await acceptedClick;
    await expect(page).toHaveURL(/\/billing\/usage$/);
    await noHistoryLeak(page);
    const refreshed = page.waitForResponse(
      (item) => item.url().endsWith('/billing/payer/data') && item.request().method() === 'GET',
    );
    await page.goBack();
    expect((await refreshed).status()).toBe(200);
    await expect(page.getByLabel('Юридическое название')).toHaveValue(draftMarker);
    await noHistoryLeak(page);
    await page.goForward();
    await expect(page).toHaveURL(/\/billing\/usage$/);
    await noHistoryLeak(page);
    const second = page.waitForResponse(
      (item) => item.url().endsWith('/billing/payer/data') && item.request().method() === 'GET',
    );
    await page.goBack();
    expect((await second).status()).toBe(200);
    await expect(page.getByLabel('Юридическое название')).toHaveValue(draftMarker);

    const closing = page.waitForEvent('dialog');
    const attempt = page.close({ runBeforeUnload: true });
    const closeDialog = await closing;
    expect(closeDialog.type()).toBe('beforeunload');
    await closeDialog.dismiss();
    await attempt;
    await expect(page.getByLabel('Юридическое название')).toHaveValue(draftMarker);
    await noHistoryLeak(page);
    const closeConfirmed = page.waitForEvent('dialog');
    const closeAttempt = page.close({ runBeforeUnload: true });
    await (await closeConfirmed).accept();
    await closeAttempt;
    const replacement = await context.newPage();
    await replacement.goto('/billing/payer');
    await expect(replacement.getByLabel('Юридическое название')).not.toHaveValue(draftMarker);
    await noHistoryLeak(replacement);
  } finally {
    await context.close();
  }
});

test('headed browser real tab focus hides and restores the unsaved draft', async ({ browser }) => {
  test.skip(
    process.env.RUN_HEADED_FOCUS !== '1',
    'Requires an explicitly requested visible headed browser.',
  );
  const context = await isolatedContext(browser);
  const otherContext = await isolatedContext(browser);
  try {
    const page = await context.newPage();
    await login(page, fixture.owner_email);
    await page.goto('/billing/payer');
    await page.getByLabel('Юридическое название').fill(draftMarker);
    await page.evaluate(() => {
      (window as unknown as { e107BlurCount: number }).e107BlurCount = 0;
      window.addEventListener('blur', () => {
        (window as unknown as { e107BlurCount: number }).e107BlurCount++;
      });
    });
    const other = await otherContext.newPage();
    await other.goto('/login');
    await other.bringToFront();
    await expect
      .poll(
        () => page.evaluate(() => (window as unknown as { e107BlurCount: number }).e107BlurCount),
        { timeout: 5000 },
      )
      .toBeGreaterThan(0);
    await noPayerVisible(page);
    await noHistoryLeak(page);
    const response = page.waitForResponse(
      (item) => item.url().endsWith('/billing/payer/data') && item.request().method() === 'GET',
      { timeout: 8000 },
    );
    await page.bringToFront();
    expect((await response).status()).toBe(200);
    await expect(page.getByLabel('Юридическое название')).toHaveValue(draftMarker);
    await noHistoryLeak(page);
  } finally {
    await otherContext.close();
    await context.close();
  }
});

test('logout after real back navigation asks before discarding an unmounted draft', async ({
  browser,
}) => {
  const context = await isolatedContext(browser);
  try {
    const page = await context.newPage();
    await login(page, fixture.owner_email);
    await page.getByRole('link', { name: 'Перейти в кабинет' }).click();
    await expect(page).toHaveURL(/\/journal$/);
    await page.getByRole('link', { name: 'Тариф и оплата', exact: true }).click();
    await expect(page).toHaveURL(/\/billing$/);
    await page.getByRole('link', { name: 'Реквизиты', exact: true }).click();
    await expect(page).toHaveURL(/\/billing\/payer$/);
    await page.getByLabel('Юридическое название').fill(draftMarker);
    await noHistoryLeak(page);
    await page.goBack();
    await expect(page).toHaveURL(/\/billing$/);
    await page.goBack();
    await expect(page).toHaveURL(/\/journal$/);
    await page.goBack();
    await expect(page).toHaveURL(/\/auth\/ready$/);
    await noHistoryLeak(page);
    const declined = page.waitForEvent('dialog');
    const logout = page.getByRole('button', { name: 'Выйти', exact: true }).click();
    const dialog = await declined;
    expect(dialog.type()).toBe('confirm');
    expect(dialog.message()).toContain('Есть несохранённые изменения');
    await dialog.dismiss();
    await logout;
    await expect(page).toHaveURL(/\/auth\/ready$/);
    await page.goForward();
    await expect(page).toHaveURL(/\/journal$/);
    await page.goForward();
    await expect(page).toHaveURL(/\/billing$/);
    const refresh = page.waitForResponse(
      (item) => item.url().endsWith('/billing/payer/data') && item.request().method() === 'GET',
    );
    await page.goForward();
    await expect(page).toHaveURL(/\/billing\/payer$/);
    expect((await refresh).status()).toBe(200);
    await expect(page.getByLabel('Юридическое название')).toHaveValue(draftMarker);
    await noHistoryLeak(page);
    await page.goBack();
    await expect(page).toHaveURL(/\/billing$/);
    await page.goBack();
    await expect(page).toHaveURL(/\/journal$/);
    await page.goBack();
    await expect(page).toHaveURL(/\/auth\/ready$/);
    const accepted = page.waitForEvent('dialog');
    const confirmLogout = page.getByRole('button', { name: 'Выйти', exact: true }).click();
    const confirm = await accepted;
    expect(confirm.type()).toBe('confirm');
    await confirm.accept();
    await confirmLogout;
    await expect(page).toHaveURL(/\/login$/);
    await login(page, fixture.owner_email);
    await page.goto('/billing/payer');
    await expect(page.getByLabel('Юридическое название')).not.toHaveValue(draftMarker);
    await noHistoryLeak(page);
  } finally {
    await context.close();
  }
});
