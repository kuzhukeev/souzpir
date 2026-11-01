import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';

process.env.PLAYWRIGHT_NO_COPY_PROMPT = '1';
test.skip(process.env.RUN_E106E_PRODUCTION !== '1', 'Requires the isolated production-build fixture.');
test.use({ trace: 'off', screenshot: 'off', video: 'off', launchOptions: { executablePath: process.env.E106E_CHROME } });
const origin = 'http://127.0.0.1:8231';

async function openTeam(page: Page) {
  await page.route('**/*', (route) => new URL(route.request().url()).origin === origin ? route.continue() : route.abort('blockedbyclient'));
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill('owner-e106e@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  await page.goto(`${origin}/team`);
  const version = await page.evaluate(() => {
    const element = document.querySelector('script[data-page="app"]');
    const initial = element ? JSON.parse(element.textContent || '{}') as { version?: string; props?: { canIssueInvitations?: boolean } } : null;
    if (initial?.props?.canIssueInvitations !== true) throw new Error('Fixture owner lacks issuance permission');
    return initial.version;
  });
  expect(typeof version === 'string' && version.length > 0).toBe(true);
  await page.getByRole('button', { name: 'Пригласить участника' }).click();
  return version!;
}

function changeFixtureRole(role: 'owner' | 'viewer') {
  const php = process.env.E106E_PHP;
  if (!php) throw new Error('Set E106E_PHP for the isolated fixture.');
  const output = execFileSync(php, [join(process.cwd(), 'tests/Browser/E1-06E/change-fixture-role.php'), role], { encoding: 'utf8', env: process.env });
  if (output.trim() !== 'Synthetic fixture role updated.') throw new Error('Synthetic fixture role was not updated.');
}

async function issue(page: Page, email: string) {
  await page.getByLabel('Электронная почта').fill(email);
  await page.getByRole('button', { name: 'Продолжить' }).click();
  await page.getByRole('button', { name: 'Подтвердить выпуск' }).click();
}

async function count(page: Page, email: string) {
  const response = await page.request.get(`${origin}/_e106e/count?email=${encodeURIComponent(email)}`);
  expect(response.status()).toBe(200);
  return (await response.json()).count as number;
}

test('production Team UI: POST 201 затем Inertia GET 200 и показ ссылки', async ({ page }) => {
  const email = `e106e-prod-${Date.now()}@example.test`;
  const version = await openTeam(page);
  const created = page.waitForResponse((response) => response.request().method() === 'POST' && response.url() === `${origin}/team/invitations`);
  const verified = page.waitForResponse((response) => response.request().method() === 'GET' && response.url() === `${origin}/team` && response.request().headers()['x-inertia'] === 'true');
  await issue(page, email);
  expect((await created).status()).toBe(201);
  const check = await verified;
  expect(check.request().headers()['x-inertia-version']).toBe(version);
  expect(check.status()).toBe(200);
  await expect(page.getByLabel('Ссылка приглашения')).toBeVisible();
  expect(await count(page, email)).toBe(1);
});

test('production Team UI: устаревшая версия даёт 409 и ссылка не показывается', async ({ page }) => {
  const email = `e106e-prod-stale-${Date.now()}@example.test`;
  const version = await openTeam(page);
  await page.route(`${origin}/team`, async (route) => {
    const headers = route.request().headers();
    if (route.request().method() === 'GET' && headers['x-inertia'] === 'true') {
      expect(headers['x-inertia-version']).toBe(version);
      await route.continue({ headers: { ...headers, 'x-inertia-version': 'deliberately-stale-fixture-version' } });
    } else await route.continue();
  });
  const created = page.waitForResponse((response) => response.request().method() === 'POST' && response.url() === `${origin}/team/invitations`);
  const verified = page.waitForResponse((response) => response.request().method() === 'GET' && response.url() === `${origin}/team` && response.request().headers()['x-inertia'] === 'true');
  await issue(page, email);
  expect((await created).status()).toBe(201);
  expect((await verified).status()).toBe(409);
  await expect(page.getByText(/Результат неизвестен/)).toBeVisible();
  await expect(page.getByLabel('Ссылка приглашения')).toHaveCount(0);
  expect(await count(page, email)).toBe(1);
});

test('production Team UI: серверный отзыв права после выпуска скрывает ссылку', async ({ page }) => {
  const email = `e106e-prod-revoke-${Date.now()}@example.test`;
  await openTeam(page);
  let revoked = false;
  try {
    await page.route(`${origin}/team`, async (route) => {
      if (!revoked && route.request().method() === 'GET' && route.request().headers()['x-inertia'] === 'true') {
        revoked = true;
        changeFixtureRole('viewer');
      }
      await route.continue();
    });
    const created = page.waitForResponse((response) => response.request().method() === 'POST' && response.url() === `${origin}/team/invitations`);
    const verified = page.waitForResponse((response) => response.request().method() === 'GET' && response.url() === `${origin}/team` && response.request().headers()['x-inertia'] === 'true');
    await issue(page, email);
    expect((await created).status()).toBe(201);
    const check = await verified;
    expect(check.status()).toBe(200);
    expect((await check.json()).props.canIssueInvitations).toBe(false);
    expect(revoked).toBe(true);
    await expect(page.getByText(/Результат неизвестен/)).toBeVisible();
    await expect(page.getByLabel('Ссылка приглашения')).toHaveCount(0);
    expect(await count(page, email)).toBe(1);
  } finally {
    if (revoked) changeFixtureRole('owner');
  }
});
