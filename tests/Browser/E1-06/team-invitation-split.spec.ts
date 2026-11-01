import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

test.skip(
  process.env.RUN_TEAM_SPLIT_E2E !== '1' || !process.env.TEAM_PHP_PATH,
  'Requires the isolated split-permission test server and fixture',
);
test.use({
  trace: 'off',
  screenshot: 'off',
  video: 'off',
  launchOptions: process.env.TEAM_CHROMIUM_PATH
    ? { executablePath: process.env.TEAM_CHROMIUM_PATH }
    : {},
});
const origin = 'http://127.0.0.1:8217';

function fixture(action: 'revoke' | 'restore' | 'count', email?: string): number | void {
  const output = execFileSync(
    process.env.TEAM_PHP_PATH!,
    ['tests/Browser/E1-06/invitation-split-fixture.php', action, ...(email ? [email] : [])],
    { env: process.env, encoding: 'utf8' },
  );
  if (action === 'count')
    return (JSON.parse(output.trim().split(/\r?\n/).at(-1)!) as { count: number }).count;
  if (!output.includes('Split fixture access changed.'))
    throw new Error('Synthetic access change failed');
}
async function login(page: Page, role: 'owner' | 'admin' | 'viewer') {
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill(`team-e106-${role}@example.test`);
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  await page.goto(`${origin}/team`);
}
async function prepare(page: Page, email: string) {
  await page.getByRole('button', { name: 'Пригласить участника' }).click();
  const dialog = page.getByRole('dialog', { name: 'Пригласить участника' });
  await dialog.getByLabel('Электронная почта').fill(email);
  await dialog.getByRole('button', { name: 'Продолжить' }).click();
  return dialog;
}
test.beforeEach(async ({ page }) => {
  await page.route('**/*', (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
});

test('owner с matrix без write и viewer с искусственным write не видят кнопку выпуска', async ({
  page,
  browser,
}) => {
  await login(page, 'owner');
  await expect(page.getByRole('link', { name: 'Роли и доступ' }).first()).toBeVisible();
  await expect(page.getByRole('button', { name: 'Пригласить участника' })).toHaveCount(0);
  const ownerProps = await page.evaluate(
    () =>
      JSON.parse(document.querySelector('script[data-page="app"]')?.textContent || '{}').props as {
        canViewRoles: boolean;
        canIssueInvitations: boolean;
        can: Record<string, boolean>;
      },
  );
  expect(ownerProps).toMatchObject({ canViewRoles: true, canIssueInvitations: false });
  expect(ownerProps.can).toMatchObject({ 'team.manage': true, 'team.write': false });
  const other = await browser.newContext();
  try {
    await other.route('**/*', (route) =>
      new URL(route.request().url()).origin === origin
        ? route.continue()
        : route.abort('blockedbyclient'),
    );
    const viewer = await other.newPage();
    await login(viewer, 'viewer');
    await expect(viewer.getByRole('button', { name: 'Пригласить участника' })).toHaveCount(0);
    const viewerProps = await viewer.evaluate(
      () =>
        JSON.parse(document.querySelector('script[data-page="app"]')?.textContent || '{}')
          .props as {
          canViewRoles: boolean;
          canIssueInvitations: boolean;
          can: Record<string, boolean>;
        },
    );
    expect(viewerProps).toMatchObject({ canViewRoles: false, canIssueInvitations: false });
    expect(viewerProps.can['team.write']).toBe(true);
  } finally {
    await other.close();
  }
});

test('admin с write без matrix выпускает одну ссылку и получает проверку права 200', async ({
  page,
}) => {
  const email = `e106-split-ok-${Date.now()}@example.test`;
  await login(page, 'admin');
  const initial = await page.evaluate(
    () =>
      JSON.parse(document.querySelector('script[data-page="app"]')?.textContent || '{}') as {
        version: string;
        props: {
          canViewRoles: boolean;
          canIssueInvitations: boolean;
          can: Record<string, boolean>;
        };
      },
  );
  expect(initial.version.length).toBeGreaterThan(0);
  expect(initial.props).toMatchObject({ canViewRoles: false, canIssueInvitations: true });
  expect(initial.props.can).toMatchObject({ 'team.manage': false, 'team.write': true });
  await expect(page.getByRole('link', { name: 'Роли и доступ' })).toHaveCount(0);
  const dialog = await prepare(page, email);
  let posts = 0;
  page.on('request', (request) => {
    if (request.method() === 'POST' && request.url() === `${origin}/team/invitations`) posts += 1;
  });
  const created = page.waitForResponse(
    (response) =>
      response.request().method() === 'POST' && response.url() === `${origin}/team/invitations`,
  );
  const checked = page.waitForResponse(
    (response) =>
      response.request().method() === 'GET' &&
      response.url() === `${origin}/team` &&
      response.request().headers()['x-inertia'] === 'true',
  );
  try {
    await dialog.getByRole('button', { name: 'Подтвердить выпуск' }).click();
    expect((await created).status()).toBe(201);
    const response = await checked;
    expect(response.status()).toBe(200);
    expect(response.request().headers()['x-inertia-version']).toBe(initial.version);
    expect((await response.json()).props).toMatchObject({
      canViewRoles: false,
      canIssueInvitations: true,
    });
    await expect(dialog.getByLabel('Ссылка приглашения')).toBeVisible();
    expect(posts).toBe(1);
    expect(fixture('count', email)).toBe(1);
  } finally {
    try {
      await dialog.getByRole('button', { name: 'Закрыть' }).click({ timeout: 1500 });
    } catch {
      await page.goto('about:blank');
    }
  }
});

test('отзыв полномочия выпуска после 201 скрывает ссылку и не повторяет выпуск', async ({
  page,
}) => {
  const email = `e106-split-revoke-${Date.now()}@example.test`;
  await login(page, 'admin');
  const dialog = await prepare(page, email);
  let revoked = false;
  let posts = 0;
  page.on('request', (request) => {
    if (request.method() === 'POST' && request.url() === `${origin}/team/invitations`) posts += 1;
  });
  await page.route(`${origin}/team`, async (route) => {
    if (
      !revoked &&
      route.request().method() === 'GET' &&
      route.request().headers()['x-inertia'] === 'true'
    ) {
      fixture('revoke');
      revoked = true;
    }
    await route.continue();
  });
  const created = page.waitForResponse(
    (response) =>
      response.request().method() === 'POST' && response.url() === `${origin}/team/invitations`,
  );
  const checked = page.waitForResponse(
    (response) =>
      response.request().method() === 'GET' &&
      response.url() === `${origin}/team` &&
      response.request().headers()['x-inertia'] === 'true',
  );
  try {
    await dialog.getByRole('button', { name: 'Подтвердить выпуск' }).click();
    expect((await created).status()).toBe(201);
    const response = await checked;
    expect(response.status()).toBe(200);
    const refreshed = (await response.json()).props as {
      canIssueInvitations: boolean;
      can: Record<string, boolean>;
    };
    expect(refreshed.canIssueInvitations).toBe(false);
    expect(refreshed.can['team.write']).toBe(true);
    expect(revoked).toBe(true);
    await expect(dialog.getByText(/Результат неизвестен/)).toBeVisible();
    await expect(dialog.getByLabel('Ссылка приглашения')).toHaveCount(0);
    await dialog.getByRole('button', { name: 'Закрыть' }).click();
    await page.getByRole('button', { name: 'Пригласить участника' }).click();
    await expect(dialog.getByText(/Результат неизвестен/)).toBeVisible();
    expect(posts).toBe(1);
    expect(fixture('count', email)).toBe(1);
  } finally {
    if (revoked) fixture('restore');
    try {
      await dialog.getByRole('button', { name: 'Закрыть' }).click({ timeout: 1500 });
    } catch {
      await page.goto('about:blank');
    }
  }
});
