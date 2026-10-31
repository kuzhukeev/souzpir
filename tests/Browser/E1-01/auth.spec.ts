import { expect, test, type Page } from '@playwright/test';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';

const require = createRequire(import.meta.url);
async function assertAccessible(page: Page): Promise<void> {
  await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') });
  const violations = await page.evaluate(async () => {
    const axe = (
      window as unknown as {
        axe: {
          run: (context: Document, options: object) => Promise<{ violations: { id: string }[] }>;
        };
      }
    ).axe;
    return (
      await axe.run(document, {
        runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] },
      })
    ).violations.map((item) => item.id);
  });
  expect(violations).toEqual([]);
}

function oneTimeCode(): string {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const digest = createHmac('sha1', Buffer.from('12345678901234567890')).update(counter).digest();
  const offset = digest[19] & 15;
  return String((digest.readUInt32BE(offset) & 0x7fffffff) % 1000000).padStart(6, '0');
}

test('real sign-in requires TOTP and establishes a session only after the challenge', async ({
  page,
}) => {
  execFileSync(
    process.env.PHP_BINARY || 'php',
    [
      '-d',
      'sys_temp_dir=.local/tmp',
      'artisan',
      'auth:test-browser-fixture',
      '--env=testing',
      '--no-ansi',
    ],
    {
      cwd: process.cwd(),
      env: { ...process.env, APP_ENV: 'testing' },
      stdio: 'pipe',
      timeout: 30000,
    },
  );
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto('/login');
  await expect(page.getByRole('heading', { name: 'Вход в кабинет' })).toBeVisible();
  await expect(page.getByLabel('Рабочая почта')).toBeVisible();
  await assertAccessible(page);
  const shots = path.join(process.cwd(), '.local/screenshots/auth');
  await mkdir(shots, { recursive: true });
  await page.screenshot({
    path: path.join(shots, 'login-1440.png'),
    fullPage: true,
    animations: 'disabled',
  });
  await page.getByLabel('Рабочая почта').fill('browser-auth@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/\/mfa\/challenge$/);
  await expect(page.getByRole('heading', { name: 'Двухфакторная проверка' })).toBeVisible();
  await page.getByLabel('Код подтверждения').fill(oneTimeCode());
  await page.getByRole('button', { name: 'Подтвердить', exact: true }).click();
  await expect(page).toHaveURL(/\/auth\/ready$/);
  await expect(page.getByRole('heading', { name: 'Вход выполнен' })).toBeVisible();
  await page.getByRole('button', { name: 'Выйти', exact: true }).click();
  await expect(page).toHaveURL(/\/login$/);
});

test('auth forms remain usable at mobile width', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/register');
  await expect(page.getByRole('heading', { name: 'Создать аккаунт' })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(
    true,
  );
  await expect(page.getByLabel('Рабочая почта')).toBeVisible();
  await assertAccessible(page);
  const shots = path.join(process.cwd(), '.local/screenshots/auth');
  await mkdir(shots, { recursive: true });
  await page.screenshot({
    path: path.join(shots, 'register-390.png'),
    fullPage: true,
    animations: 'disabled',
  });
});

test('enabling MFA revokes another session and its remembered login', async ({ browser }) => {
  execFileSync(
    process.env.PHP_BINARY || 'php',
    [
      '-d',
      'sys_temp_dir=.local/tmp',
      'artisan',
      'auth:test-browser-fixture',
      '--env=testing',
      '--without-mfa',
      '--no-ansi',
    ],
    {
      cwd: process.cwd(),
      env: { ...process.env, APP_ENV: 'testing' },
      stdio: 'pipe',
      timeout: 30000,
    },
  );
  const baseURL = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8101';
  const first = await browser.newContext({ baseURL });
  const second = await browser.newContext({ baseURL });
  const rememberedOnly = await browser.newContext({ baseURL });
  try {
    const firstPage = await first.newPage();
    const secondPage = await second.newPage();
    for (const page of [firstPage, secondPage]) {
      await page.goto('/login');
      await page.getByLabel('Рабочая почта').fill('browser-auth@example.test');
      await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
      await page.getByLabel('Запомнить меня').check();
      await page.getByRole('button', { name: 'Войти', exact: true }).click();
      await expect(page).toHaveURL(/\/auth\/ready$/);
    }
    const rememberCookies = (await second.cookies()).filter((cookie) =>
      cookie.name.startsWith('remember_web_'),
    );
    expect(rememberCookies).toHaveLength(1);
    await rememberedOnly.addCookies(rememberCookies);
    await firstPage.getByLabel('Текущий пароль').fill('test-only-password-123');
    await firstPage.getByRole('button', { name: 'Настроить двухфакторную проверку' }).click();
    await expect(firstPage).toHaveURL(/\/mfa\/setup$/);
    const secret = (await firstPage.locator('p.font-mono').innerText()).trim();
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of secret) bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    const bytes = Buffer.from((bits.match(/.{8}/g) || []).map((byte) => parseInt(byte, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const digest = createHmac('sha1', bytes).update(counter).digest();
    const offset = digest[19] & 15;
    const code = String((digest.readUInt32BE(offset) & 0x7fffffff) % 1000000).padStart(6, '0');
    await firstPage.getByLabel('Код из приложения').fill(code);
    await firstPage.getByRole('button', { name: 'Включить проверку' }).click();
    await expect(firstPage).toHaveURL(/\/auth\/ready$/);
    await expect(firstPage.getByText('включена', { exact: true })).toBeVisible();
    await secondPage.goto('/auth/ready');
    await expect(secondPage).toHaveURL(/\/login$/);
    const rememberedPage = await rememberedOnly.newPage();
    await rememberedPage.goto('/auth/ready');
    await expect(rememberedPage).toHaveURL(/\/login$/);
    await firstPage.reload();
    await expect(firstPage).toHaveURL(/\/auth\/ready$/);
  } finally {
    await Promise.all([first.close(), second.close(), rememberedOnly.close()]);
  }
});
