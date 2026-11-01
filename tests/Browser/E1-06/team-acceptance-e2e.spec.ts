import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

test.skip(process.env.RUN_TEAM_INVITATION_E2E !== '1' || !process.env.TEAM_PHP_PATH, 'Requires isolated Team PostgreSQL fixture');
test.use({
  trace: 'off', screenshot: 'off', video: 'off',
  launchOptions: process.env.TEAM_CHROMIUM_PATH ? { executablePath: process.env.TEAM_CHROMIUM_PATH } : {},
});
const origin = 'http://127.0.0.1:8217';

function fixture(email: string, mode: 'setup' | 'inspect') {
  return execFileSync(process.env.TEAM_PHP_PATH!, ['tests/Browser/E1-06/prepare-fixture.php'], {
    env: { ...process.env, E106_ACCEPT_EMAIL: email, E106_ACCEPT_MODE: mode }, encoding: 'utf8',
  }).trim();
}

test('выпуск из команды и принятие приглашения создают участника с выбранным брендом', async ({ page, browser }) => {
  const email = `e106-accept-${Date.now()}@example.test`;
  expect(fixture(email, 'setup')).toBe('Synthetic guest ready.');
  await page.route('**/*', (route) => new URL(route.request().url()).origin === origin ? route.continue() : route.abort('blockedbyclient'));
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill('team-e106-owner@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  await page.goto(`${origin}/team`);
  await page.getByRole('button', { name: 'Пригласить участника' }).click();
  const dialog = page.getByRole('dialog', { name: 'Пригласить участника' });
  await dialog.getByLabel('Электронная почта').fill(email);
  await dialog.getByRole('button', { name: 'Продолжить' }).click();
  await expect(dialog.getByText('Письмо автоматически не отправляется.')).toBeVisible();
  const issuance = page.waitForResponse((response) => response.request().method() === 'POST' && response.url() === `${origin}/team/invitations`);
  await dialog.getByRole('button', { name: 'Подтвердить выпуск' }).click();
  const field = dialog.getByLabel('Ссылка приглашения');
  const link = await (async () => {
    try {
      expect((await issuance).status()).toBe(201);
      await expect(field).toBeVisible();
      return await field.inputValue();
    } finally {
      try {
        await dialog.getByRole('button', { name: 'Закрыть' }).click({ timeout: 1500 });
      } catch {
        await page.goto('about:blank');
      }
    }
  })();
  const token = new URL(link).hash.slice(1);
  expect(new URL(link).origin === origin && token.length === 64).toBe(true);
  await expect(field).toHaveCount(0);

  const guestContext = await browser.newContext();
  let leaked = false;
  try {
    const guest = await guestContext.newPage();
    await guest.route('**/*', (route) => {
      const request = route.request();
      const url = new URL(request.url());
      if (url.origin !== origin) return route.abort('blockedbyclient');
      if (request.url().includes(token) || (url.pathname !== '/invitations/exchange' && (request.postData() ?? '').includes(token))) leaked = true;
      return route.continue();
    });
    const openLink = async () => {
      try {
        await guest.goto(link);
      } catch {
        throw new Error('Не удалось открыть приглашение.');
      }
    };
    await openLink();
    await expect.poll(() => guest.evaluate(() => location.pathname).catch(() => '')).toBe('/invitations/show');
    await guest.getByRole('link', { name: 'Войти' }).click();
    await guest.getByLabel('Рабочая почта').fill(email);
    await guest.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
    await guest.getByRole('button', { name: 'Войти', exact: true }).click();
    await expect(guest).toHaveURL(/\/invitations\/show$/);
    await guest.getByRole('button', { name: 'Принять приглашение' }).click();
    await expect(guest).toHaveURL(/\/auth\/ready$/);
    await openLink();
    await expect(guest.getByText('Не удалось открыть приглашение. Запросите новую ссылку.')).toBeVisible();
    expect(await guest.evaluate(() => location.hash)).toBe('');
    expect(leaked).toBe(false);
  } finally {
    await guestContext.close();
  }

  const result = JSON.parse(fixture(email, 'inspect')) as {
    role: string; status: string; brands: string[]; expected_brand: string; accepted: boolean;
    created_audit: number; accepted_audit: number;
  };
  expect(result).toMatchObject({ role: 'viewer', status: 'active', accepted: true, created_audit: 1, accepted_audit: 1 });
  expect(result.brands).toEqual([result.expected_brand]);
  await page.reload();
  const ownerList = page.getByRole('region', { name: 'Таблица участников' });
  const member = ownerList.getByRole('row').filter({ hasText: email });
  await expect(member).toContainText('Наблюдатель');
  await expect(member).toContainText('Пиротехника — Северные огни');
  await expect(page.getByRole('region', { name: 'Приглашения в ожидании' }).getByText(email)).toHaveCount(0);
});
