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
