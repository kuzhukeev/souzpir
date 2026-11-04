import { test, expect } from '@playwright/test';
import { spawn, execFileSync, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import { mkdir, unlink } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';

const localChrome = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
test.use({ launchOptions: { executablePath: process.platform === 'win32' && existsSync(localChrome) ? localChrome : undefined } });

const php = process.env.PHP_BINARY || 'php';
const root = process.cwd();
const shots = path.join(root, '.local/screenshots/sp-e1-01h');
let vite: ChildProcess;
let server: ChildProcess;
let base: string;

async function freePort(): Promise<number> {
  return await new Promise((resolve) => {
    const socket = createServer();
    socket.listen(0, '127.0.0.1', () => {
      const address = socket.address();
      const port = typeof address === 'object' && address ? address.port : 0;
      socket.close(() => resolve(port));
    });
  });
}

async function ready(url: string): Promise<void> {
  for (let attempt = 0; attempt < 80; attempt++) {
    try {
      const response = await fetch(url);
      if (response.ok) return;
    } catch {
      // The server has not started yet.
    }
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error(`Server did not start: ${url}`);
}

test.beforeAll(async () => {
  test.setTimeout(120_000);
  const vitePort = await freePort();
  const appPort = await freePort();
  base = `http://127.0.0.1:${appPort}`;
  execFileSync(php, ['artisan', 'auth:test-browser-fixture', '--env=testing', '--without-mfa', '--no-ansi'], {
    cwd: root, env: { ...process.env, APP_ENV: 'testing' }, timeout: 60_000,
  });
  execFileSync(php, ['tests/Browser/E1-01H/fixture.php'], {
    cwd: root, env: { ...process.env, APP_ENV: 'testing' }, timeout: 30_000,
  });
  vite = spawn(process.execPath, ['node_modules/vite/bin/vite.js', '--host', '127.0.0.1', '--port', String(vitePort), '--strictPort'], {
    cwd: root, env: { ...process.env, APP_ENV: 'testing', LARAVEL_BYPASS_ENV_CHECK: '1' }, stdio: 'ignore',
  });
  await ready(`http://127.0.0.1:${vitePort}/@vite/client`);
  server = spawn(php, ['-S', `127.0.0.1:${appPort}`, '-t', 'public', 'tests/Browser/E1-01H/router.php'], {
    cwd: root, env: { ...process.env, APP_ENV: 'testing', APP_URL: base }, stdio: 'ignore',
  });
  await ready(base + '/register');
  await mkdir(shots, { recursive: true });
});

test.afterAll(async () => {
  server?.kill();
  vite?.kill();
  await unlink(path.join(root, 'public/hot')).catch(() => {});
});

test('registration, verification and invitation match their key states at 1440', async ({ page, browser }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(base + '/register');
  await expect(page.getByRole('heading', { name: 'Создать аккаунт' })).toBeVisible();
  await page.getByLabel('Пароль', { exact: true }).fill('Strong-password-123');
  await expect(page.getByRole('meter', { name: 'Надёжность пароля' })).toHaveAttribute('aria-valuenow', '4');
  await expect(page.getByText('Создаём аккаунт…')).toHaveCount(0);
  await page.screenshot({ path: path.join(shots, 'register-1440.png'), fullPage: true, animations: 'disabled' });
  await page.getByLabel('Телефон · необязательно').fill('89991234567');
  await expect(page.getByLabel('Телефон · необязательно')).toHaveValue('+7 (999) 123-45-67');
  await page.getByLabel('Имя').fill('Тест');
  await page.getByLabel('Рабочая почта').fill('new-browser@example.test');
  await page.getByLabel('Повторите пароль').fill('Strong-password-123');
  await page.getByLabel('Код создания кабинета').fill('x'.repeat(40));
  await page.getByLabel('Я принимаю условия использования и обработки данных').check();
  await page.getByLabel('Название компании и тексты диалогов обрабатываются внешними ИИ-провайдерами через OpenRouter').check();
  await page.route('**/register', async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 800));
    await route.continue();
  });
  await page.getByRole('button', { name: 'Зарегистрироваться' }).click();
  await expect(page.getByRole('button', { name: 'Создаём аккаунт…' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Зарегистрироваться' })).toBeVisible();
  await page.unroute('**/register');


  await page.goto(base + '/login');
  await page.getByLabel('Рабочая почта').fill('verify-browser@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/\/email\/verify$/);
  await expect(page.getByRole('button', { name: 'Изменить почту' })).toBeVisible();
  await page.screenshot({ path: path.join(shots, 'verify-1440.png'), fullPage: true, animations: 'disabled' });

  const invitationPage = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  await invitationPage.goto(base + '/invitations/open#' + 'A'.repeat(64));
  await expect(invitationPage).toHaveURL(/\/invitations\/show$/);
  await expect(invitationPage.getByText('Browser Fixture приглашает вас')).toBeVisible();
  await expect(invitationPage.getByText('Почта указана в приглашении')).toBeVisible();
  await invitationPage.screenshot({ path: path.join(shots, 'invitation-1440.png'), fullPage: true, animations: 'disabled' });
  await invitationPage.close();
});
