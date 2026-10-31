import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { createHmac } from 'node:crypto';
const base = process.env.PROFILE_APP_URL || 'http://127.0.0.1:8238';
test.use({
  launchOptions: process.env.PROFILE_CHROMIUM_PATH
    ? { executablePath: process.env.PROFILE_CHROMIUM_PATH }
    : {},
});
async function guard(context: BrowserContext) {
  await context.route('**/*', (route) =>
    new URL(route.request().url()).origin === base
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
}
async function login(
  page: Page,
  password = 'test-only-password-123',
  email = 'profile-e108@example.test',
) {
  await page.goto(base + '/login');
  await page.locator('input[type=email]').fill(email);
  await page.locator('input[type=password]').fill(password);
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).not.toHaveURL(/\/login$/);
  await page.goto(base + '/profile');
  await expect(page.getByRole('heading', { name: 'Личные данные' })).toBeVisible();
}
function totp(secret: string, offset = 0) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const char of secret) bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
  const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((byte) => parseInt(byte, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000) + offset));
  const digest = createHmac('sha1', key).update(counter).digest();
  const position = digest[digest.length - 1] & 15;
  return ((digest.readUInt32BE(position) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
}
test('actual profile, matrix, test delivery, password, sessions and MFA persist with real CSRF', async ({
  browser,
}) => {
  test.setTimeout(150000);
  const primary = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const secondary = await browser.newContext();
  const unrelated = await browser.newContext();
  await Promise.all([guard(primary), guard(secondary), guard(unrelated)]);
  primary.setDefaultTimeout(15000);
  secondary.setDefaultTimeout(15000);
  unrelated.setDefaultTimeout(15000);
  const page = await primary.newPage(),
    second = await secondary.newPage(),
    other = await unrelated.newPage();
  try {
    await login(page);
    await login(second);
    await login(other, 'test-only-password-123', 'profile-e108-other@example.test');
    await page.getByRole('link', { name: 'Настройки', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Уведомления');
    await page.getByRole('link', { name: 'Профиль', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Профиль');
    await page.getByLabel('Имя', { exact: true }).fill('');
    await page.locator('form').evaluate((form) => {
      (form as HTMLFormElement).noValidate = true;
    });
    await page.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
    await expect(page.getByLabel('Имя', { exact: true })).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByRole('alert')).toContainText('Заполните поле');
    await expect(page.getByRole('alert')).not.toContainText('validation.');
    await page.screenshot({
      path: '.local/screenshots/e1-08/real-profile-validation.png',
      fullPage: true,
    });
    await page.getByLabel('Имя', { exact: true }).fill('Анна Проверочная');
    await page.getByLabel('Телефон', { exact: true }).fill('+7 999 555-44-33');
    await page.getByRole('combobox', { name: 'Часовой пояс' }).click();
    await page.getByRole('combobox', { name: 'Поиск: Часовой пояс' }).fill('Asia/Tokyo');
    await page.getByRole('option', { name: 'Asia/Tokyo', exact: true }).click();
    await page.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('сохран');
    await page.reload();
    await expect(page.getByLabel('Имя', { exact: true })).toHaveValue('Анна Проверочная');
    await expect(page.getByRole('combobox', { name: 'Часовой пояс' })).toContainText('Asia/Tokyo');
    await page.getByRole('combobox', { name: 'Часовой пояс' }).click();
    await page.getByRole('combobox', { name: 'Поиск: Часовой пояс' }).fill('По умолчанию');
    await page.getByRole('option', { name: 'По умолчанию · Europe/Moscow', exact: true }).click();
    await page.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('сохран');
    await page.reload();
    await expect(page.getByRole('combobox', { name: 'Часовой пояс' })).toContainText(
      'Europe/Moscow',
    );
    await page.getByRole('combobox', { name: 'Часовой пояс' }).click();
    await page.getByRole('combobox', { name: 'Поиск: Часовой пояс' }).fill('Asia/Tokyo');
    await page.getByRole('option', { name: 'Asia/Tokyo', exact: true }).click();
    await page.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('сохран');
    const png = await page.evaluate(() => {
      const canvas = document.createElement('canvas');
      canvas.width = canvas.height = 32;
      const ctx = canvas.getContext('2d')!;
      ctx.fillStyle = '#14834b';
      ctx.fillRect(0, 0, 32, 32);
      return canvas.toDataURL('image/png').split(',')[1];
    });
    await page.locator('input[type=file]').setInputFiles({
      name: 'avatar.png',
      mimeType: 'image/png',
      buffer: Buffer.from(png, 'base64'),
    });
    await expect(page.getByRole('img', { name: 'Анна Проверочная', exact: true })).toBeVisible();
    const avatar = page.getByRole('img', { name: 'Анна Проверочная', exact: true });
    const firstAvatarUrl = await avatar.getAttribute('src');
    await expect(page.locator('input[type=file]')).toHaveValue('');
    await page
      .locator('input[type=file]')
      .setInputFiles({
        name: 'avatar.png',
        mimeType: 'image/png',
        buffer: Buffer.from(png, 'base64'),
      });
    await expect(avatar).not.toHaveAttribute('src', firstAvatarUrl!);
    await page.screenshot({
      path: '.local/screenshots/e1-08/real-profile-saved.png',
      fullPage: true,
    });
    await page
      .getByRole('navigation', { name: 'Личные настройки', exact: true })
      .getByRole('link', { name: 'Уведомления', exact: true })
      .click();
    const matrix = page.getByRole('switch', {
      name: 'Диалог ждёт менеджера: В кабинете',
      exact: true,
    });
    await expect(matrix).toHaveAttribute('aria-checked', 'true');
    await matrix.click();
    await page.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('сохран');
    await page.reload();
    await expect(matrix).toHaveAttribute('aria-checked', 'false');
    await page.getByRole('button', { name: 'Отправить тестовое', exact: true }).click();
    await expect(
      page.getByRole('heading', { name: 'Последние тестовые уведомления' }),
    ).toBeVisible();
    await expect(page.getByText('Доставлено', { exact: true })).toBeVisible();
    await page.reload();
    await expect(page.getByText('Доставлено', { exact: true })).toBeVisible();
    await page.screenshot({
      path: '.local/screenshots/e1-08/real-notifications-saved.png',
      fullPage: true,
    });
    await page.getByRole('link', { name: 'Безопасность', exact: true }).click();
    await page.getByRole('button', { name: 'Завершить', exact: true }).first().click();
    await page.getByRole('dialog').getByRole('button', { name: 'Завершить', exact: true }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await second.goto(base + '/profile');
    await expect(second).toHaveURL(/\/login$/);
    await other.reload();
    await expect(other.getByRole('heading', { name: 'Личные данные' })).toBeVisible();
    await login(second);
    await page.reload();
    await page.getByRole('button', { name: 'Завершить все, кроме текущей', exact: true }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Завершить', exact: true }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await second.goto(base + '/profile');
    await expect(second).toHaveURL(/\/login$/);
    await login(second);
    await page.getByLabel('Текущий пароль', { exact: true }).fill('test-only-password-123');
    await page.getByLabel('Новый пароль', { exact: true }).fill('test-only-password-456');
    await page.getByLabel('Повторите новый пароль').fill('test-only-password-456');
    await page.getByRole('button', { name: 'Сохранить пароль' }).click();
    await expect(page.getByRole('status')).toContainText('Пароль');
    await second.goto(base + '/profile');
    await expect(second).toHaveURL(/\/login$/);
    await expect(page.getByLabel('Текущий пароль', { exact: true })).toHaveValue('');
    await page.getByRole('button', { name: 'Включить защиту' }).click();
    await page.getByRole('dialog').getByLabel('Текущий пароль').fill('test-only-password-456');
    const setupResponse = page.waitForResponse(
      (response) =>
        response.request().method() === 'GET' &&
        new URL(response.url()).pathname === '/profile/security' &&
        response.headers()['x-inertia'] === 'true',
    );
    await page.getByRole('button', { name: 'Продолжить', exact: true }).click();
    expect((await setupResponse).headers()['cache-control']).toContain('no-store');
    await expect(
      page.getByRole('img', { name: 'QR-код для приложения-аутентификатора' }),
    ).toBeVisible();
    const secret = (await page.getByRole('dialog').locator('code').innerText()).trim();
    const serialized = await page.evaluate(() => JSON.stringify(history.state));
    expect(serialized.includes(secret)).toBeFalsy();
    await page.getByLabel('Код из приложения').fill(totp(secret));
    await page.getByRole('button', { name: 'Подтвердить и включить' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.reload();
    await expect(page.getByText('Включена', { exact: true })).toBeVisible();
    await page.screenshot({
      path: '.local/screenshots/e1-08/real-security-enabled.png',
      fullPage: true,
    });
    await page.getByRole('button', { name: 'Отключить защиту' }).click();
    await page.getByRole('dialog').getByLabel('Текущий пароль').fill('test-only-password-456');
    await page.getByLabel('Код из приложения').fill(totp(secret, 1));
    await page.getByRole('dialog').getByRole('button', { name: 'Отключить защиту' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.getByText('Не включена', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Включить защиту' }).click();
    await page.getByRole('dialog').getByLabel('Текущий пароль').fill('test-only-password-456');
    await page.getByRole('button', { name: 'Продолжить', exact: true }).click();
    await expect(
      page.getByRole('img', { name: 'QR-код для приложения-аутентификатора' }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Отмена', exact: true }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.reload();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await other.reload();
    await expect(other.getByRole('heading', { name: 'Личные данные' })).toBeVisible();
  } finally {
    await Promise.allSettled([primary.close(), secondary.close(), unrelated.close()]);
  }
});
