import { expect, test, type BrowserContext, type Page } from '@playwright/test';

const base = process.env.PROFILE_APP_URL || 'http://127.0.0.1:8238';
const initialPassword = process.env.E108_SECURITY_INITIAL_PASSWORD || 'test-only-password-123';
const replacementPassword = process.env.E108_SECURITY_REPLACEMENT_PASSWORD || 'replacement-test-password-456';
test.use({ launchOptions: process.env.PROFILE_CHROMIUM_PATH ? { executablePath: process.env.PROFILE_CHROMIUM_PATH } : {} });
test.describe.configure({ mode: 'serial' });

async function localOnly(context: BrowserContext) {
  await context.route('**/*', (route) => new URL(route.request().url()).origin === base ? route.continue() : route.abort('blockedbyclient'));
}

async function login(page: Page, email: string, password: string) {
  await page.goto(base + '/login');
  await page.locator('input[type=email]').fill(email);
  await page.locator('input[type=password]').fill(password);
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).not.toHaveURL(/\/login$/);
  await page.goto(base + '/profile/security');
  await expect(page.getByRole('heading', { name: 'Смена пароля' })).toBeVisible();
}

test('a committed password change with a lost response is reported as uncertain', async ({ browser }) => {
  const primary = await browser.newContext();
  const check = await browser.newContext();
  await Promise.all([localOnly(primary), localOnly(check)]);
  const page = await primary.newPage();
  try {
    await login(page, 'profile-e108@example.test', initialPassword);
    await page.route('**/profile/security/password', async (route) => {
      expect(route.request().method()).toBe('PUT');
      const response = await route.fetch({ maxRedirects: 0 });
      expect([302, 303]).toContain(response.status());
      // The real server has responded after commit; lose only the browser response.
      await route.abort('failed');
    });
    await page.getByLabel('Текущий пароль', { exact: true }).fill(initialPassword);
    await page.getByLabel('Новый пароль', { exact: true }).fill(replacementPassword);
    await page.getByLabel('Повторите новый пароль', { exact: true }).fill(replacementPassword);
    await page.getByRole('button', { name: 'Сохранить пароль' }).click();
    await expect(page.getByRole('alert')).toContainText('Пароль мог измениться или запрос ещё выполняется');
    await expect(page.getByRole('alert')).not.toContainText('Изменения не сохранены');
    await expect(page.getByLabel('Текущий пароль', { exact: true })).toHaveValue('');
    await login(await check.newPage(), 'profile-e108@example.test', replacementPassword);
  } finally {
    await Promise.all([primary.close(), check.close()]);
  }
});

test('committed session revocation with a lost response closes confirmation and reports uncertainty', async ({ browser }) => {
  const primary = await browser.newContext();
  const other = await browser.newContext();
  await Promise.all([localOnly(primary), localOnly(other)]);
  const page = await primary.newPage();
  const second = await other.newPage();
  try {
    await login(page, 'profile-e108-other@example.test', 'test-only-password-123');
    await login(second, 'profile-e108-other@example.test', 'test-only-password-123');
    await page.reload();
    await page.route('**/profile/security/sessions', async (route) => {
      expect(route.request().method()).toBe('DELETE');
      const response = await route.fetch({ maxRedirects: 0 });
      expect([302, 303]).toContain(response.status());
      await route.abort('failed');
    });
    await page.getByRole('button', { name: 'Завершить все, кроме текущей' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Завершить' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.getByRole('alert')).toContainText('Сессии могли завершиться или запрос ещё выполняется');
    await expect(page.getByRole('alert')).not.toContainText('Изменения не сохранены');
    await second.goto(base + '/profile/security');
    await expect(second).toHaveURL(/\/login$/);
  } finally {
    await Promise.all([primary.close(), other.close()]);
  }
});
