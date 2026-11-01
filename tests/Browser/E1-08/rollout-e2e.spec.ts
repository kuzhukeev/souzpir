import { expect, test } from '@playwright/test';
import { createHmac } from 'node:crypto';
import { spawn, spawnSync, type ChildProcess } from 'node:child_process';
import { existsSync, lstatSync, realpathSync, unlinkSync } from 'node:fs';
import { createConnection } from 'node:net';
import path from 'node:path';

const project = process.cwd();
const root = path.join(project, '.local', 'tmp', 'rollout-synthetic');
const php = 'C:/OSPanel/modules/PHP-8.4/php.exe';
const fixture = path.join(project, 'tests/Browser/E1-08/rollout-fixture.php');
const router = path.join(project, 'tests/Browser/E1-08/rollout-router.php');
const baseline = 'fd7b844';
const base = 'http://souzpir.local:8292';
const environment = { ...process.env, APP_ENV: 'local', MFA_LOCAL_FIXTURE: '1', APP_URL: base, SESSION_DRIVER: 'file', CACHE_STORE: 'file', PROFILE_SETTINGS_ENABLED: 'false' };
test.use({ launchOptions: { executablePath: process.env.MFA_BROWSER_EXECUTABLE_PATH, args: ['--host-resolver-rules=MAP souzpir.local 127.0.0.1', '--no-proxy-server'] }, trace: 'off' });

function run(command: string, args: string[], cwd = root): void {
  const result = spawnSync(command, args, { cwd, env: environment, encoding: 'utf8', timeout: 60000 });
  if (result.status !== 0) throw new Error(`${command} failed: ${result.stderr}`);
}
function phpFixture(mode: string): void {
  run(php, ['-d', `sys_temp_dir=${path.join(project, '.local/tmp')}`, fixture, mode]);
}
function restoreOld(): void {
  if (realpathSync(root).toLowerCase() !== path.resolve(root).toLowerCase()) throw new Error('Unexpected rollout root');
  const diff = spawnSync('git', ['diff', '--name-only', '--diff-filter=A', baseline, 'HEAD'], { cwd: project, encoding: 'utf8' });
  if (diff.status !== 0) throw new Error('Unable to list new-version files');
  for (const name of diff.stdout.split(/\r?\n/).filter(Boolean)) {
    const file = path.resolve(root, name);
    if (!file.toLowerCase().startsWith((root + path.sep).toLowerCase()) || name.startsWith('.local/')) throw new Error('Unsafe rollout path');
    if (existsSync(file)) {
      const stat = lstatSync(file);
      if (!stat.isFile() || stat.isSymbolicLink()) throw new Error('Unsafe rollout file');
      unlinkSync(file);
    }
  }
  run('tar', ['-xf', path.join(project, '.local/tmp/rollout-synthetic-old.tar'), '-C', root]);
  run('node', ['C:/OSPanel/home/souzpir.local/node_modules/vite/bin/vite.js', 'build']);
}
async function stop(server: ChildProcess): Promise<void> {
  if (server.exitCode !== null) return;
  server.kill();
  await new Promise<void>((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('Synthetic server did not stop')), 10000);
    server.once('exit', () => { clearTimeout(timer); resolve(); });
  });
}
async function start(enabled: boolean): Promise<ChildProcess> {
  const server = spawn(php, ['-d', `sys_temp_dir=${path.join(project, '.local/tmp')}`, '-S', '127.0.0.1:8292', '-t', 'public', router], { cwd: root, env: { ...environment, PROFILE_SETTINGS_ENABLED: enabled ? 'true' : 'false' }, stdio: 'ignore' });
  for (let n = 0; n < 100; n++) {
    if (server.exitCode !== null) throw new Error('Synthetic server exited');
    const listening = await new Promise<boolean>(resolve => {
      const socket = createConnection({ host: '127.0.0.1', port: 8292 });
      socket.once('connect', () => { socket.destroy(); resolve(true); });
      socket.once('error', () => resolve(false));
    });
    if (listening) return server;
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  await stop(server);
  throw new Error('Synthetic server did not start');
}
function totp(): string {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const digest = createHmac('sha1', Buffer.from('12345678901234567890')).update(counter).digest();
  return ((digest.readUInt32BE(digest[digest.length - 1] & 15) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
}

test('schema-first rollout preserves a file-backed MFA session and profile data', async ({ page, context }) => {
  test.skip(process.env.RUN_SYNTHETIC_ROLLOUT !== '1', 'Requires the separately provisioned synthetic rollout fixture');
  expect(process.env.RUN_SYNTHETIC_ROLLOUT).toBe('1');
  if (existsSync(path.join(root, 'config/features/E1-08.php'))) restoreOld();
  expect(existsSync(path.join(root, 'config/features/E1-08.php'))).toBe(false);
  await context.route('**/*', async route => {
    if (new URL(route.request().url()).origin === base) await route.continue();
    else await route.abort('blockedbyclient');
  });
  phpFixture('seed');
  let server = await start(false);
  try {
    await page.goto(base + '/login');
    await page.locator('input[type=email]').fill('rollout-owner@example.test');
    await page.locator('input[type=password]').fill('fixture-only-password');
    await page.getByRole('button', { name: 'Войти', exact: true }).click();
    await page.waitForURL('**/mfa/challenge');
    await page.getByLabel('Код подтверждения').fill(totp());
    await page.getByRole('button', { name: 'Подтвердить' }).click();
    await page.waitForURL(url => !url.pathname.includes('/mfa/'));
    await page.goto(base + '/billing');
    await expect(page.getByRole('heading', { name: 'Текущий план и лимиты' })).toBeVisible();

    // Only additive E1-08 migrations, while the old server remains available.
    phpFixture('migrate');
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Текущий план и лимиты' })).toBeVisible();
    await stop(server);
    run('tar', ['-xf', path.join(project, '.local/tmp/rollout-synthetic-new.tar'), '-C', root]);
    run('node', ['C:/OSPanel/home/souzpir.local/node_modules/vite/bin/vite.js', 'build']);

    server = await start(false);
    await page.goto(base + '/billing');
    await expect(page.getByRole('heading', { name: 'Текущий план и лимиты' })).toBeVisible();
    expect((await page.goto(base + '/profile'))?.status()).toBe(404);
    await stop(server);

    server = await start(true);
    await page.goto(base + '/billing');
    await expect(page.getByRole('heading', { name: 'Текущий план и лимиты' })).toBeVisible();
    expect((await page.goto(base + '/profile'))?.status()).toBe(200);
    phpFixture('write');
    await stop(server);

    server = await start(false);
    await page.goto(base + '/billing');
    await expect(page.getByRole('heading', { name: 'Текущий план и лимиты' })).toBeVisible();
    expect((await page.goto(base + '/profile'))?.status()).toBe(404);
    phpFixture('verify');
    await stop(server);

    // Revert code only; keep the same runtime and all migrated data.
    restoreOld();
    server = await start(false);
    await page.goto(base + '/billing');
    await expect(page.getByRole('heading', { name: 'Текущий план и лимиты' })).toBeVisible();
    phpFixture('verify');
  } finally {
    await stop(server);
  }
});
