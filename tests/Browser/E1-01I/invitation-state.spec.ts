import { test, expect } from '@playwright/test';
import { spawn, execFileSync, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import { existsSync } from 'node:fs';

const localChrome = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
test.use({ launchOptions: { executablePath: process.platform === 'win32' && existsSync(localChrome) ? localChrome : undefined } });

const php = process.env.PHP_BINARY || 'php';
const root = process.cwd();
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
  execFileSync(php, ['tests/Browser/E1-01I/invitation-state-fixture.php'], {
    cwd: root, env: { ...process.env, APP_ENV: 'testing' }, timeout: 30_000,
  });
  vite = spawn(process.execPath, ['node_modules/vite/bin/vite.js', '--host', '127.0.0.1', '--port', String(vitePort), '--strictPort'], {
    cwd: root, env: { ...process.env, APP_ENV: 'testing', LARAVEL_BYPASS_ENV_CHECK: '1' }, stdio: 'ignore',
  });
  await ready(`http://127.0.0.1:${vitePort}/@vite/client`);
  server = spawn(php, ['-S', `127.0.0.1:${appPort}`, '-t', 'public', 'tests/Browser/E1-01I/invitation-state-router.php'], {
    cwd: root, env: { ...process.env, APP_ENV: 'testing', APP_URL: base }, stdio: 'ignore',
  });
  await ready(base + '/login');
});

test.afterAll(async () => {
  server?.kill();
  vite?.kill();
});

const anonymousStates: Array<{ state: string; token: string; title: string }> = [
  { state: 'expired', token: 'X'.repeat(64), title: 'Срок приглашения истёк' },
  { state: 'revoked', token: 'Y'.repeat(64), title: 'Приглашение отозвано' },
  { state: 'accepted', token: 'Z'.repeat(64), title: 'Приглашение уже принято' },
];

for (const { state, token, title } of anonymousStates) {
  test(`/invitations/open переходит на /invitations/state?state=${state}`, async ({ page }) => {
    await page.goto(`${base}/invitations/open#${token}`);
    await expect(page).toHaveURL(new RegExp(`/invitations/state\\?state=${state}$`));
    await expect(page.getByRole('heading', { name: title })).toBeVisible();
  });
}

test('/invitations/open переходит на /invitations/state?state=email_mismatch для чужого аккаунта', async ({ page }) => {
  await page.goto(`${base}/login`);
  await page.getByLabel('Рабочая почта').fill('browser-auth@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await page.waitForURL((url) => !url.pathname.startsWith('/login'));
  await page.goto(`${base}/invitations/open#${'M'.repeat(64)}`);
  await expect(page).toHaveURL(/\/invitations\/state\?state=email_mismatch$/);
  await expect(page.getByRole('heading', { name: 'Почта не совпадает' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Выйти из аккаунта' })).toBeVisible();
});
