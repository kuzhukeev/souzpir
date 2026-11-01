import { expect, test, type Page } from '@playwright/test';

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
    return element ? (JSON.parse(element.textContent || '{}') as { version?: string }).version : null;
  });
  expect(typeof version === 'string' && version.length > 0).toBe(true);
  await page.getByRole('button', { name: 'Пригласить участника' }).click();
  return version!;
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
