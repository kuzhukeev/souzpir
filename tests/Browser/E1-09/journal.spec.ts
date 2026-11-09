import { expect, test, type Page } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import { createRequire } from 'node:module';
import { mkdir, unlink } from 'node:fs/promises';
import path from 'node:path';

const require = createRequire(import.meta.url);
test.setTimeout(90000);
let base: string;
let vite: ChildProcess;
let server: ChildProcess;
async function freePort(): Promise<number> {
  return new Promise((resolve) => {
    const socket = createServer().listen(0, '127.0.0.1', () => {
      const address = socket.address();
      const port = typeof address === 'object' && address ? address.port : 0;
      socket.close(() => resolve(port));
    });
  });
}
async function ready(url: string) {
  for (let i = 0; i < 80; i++) {
    try { if ((await fetch(url)).ok) return; } catch { /* starting */ }
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error(`Server did not start: ${url}`);
}
test.beforeAll(async () => {
  test.setTimeout(120000);
  const php = process.env.PHP_BINARY || 'php';
  const options = {
    cwd: process.cwd(),
    env: { ...process.env, APP_ENV: 'testing' },
    stdio: 'pipe' as const,
    timeout: 30000,
  };
  execFileSync(
    php,
    [
      '-d',
      'sys_temp_dir=.local/tmp',
      'artisan',
      'auth:test-browser-fixture',
      '--without-mfa',
      '--env=testing',
      '--no-ansi',
    ],
    options,
  );
  execFileSync(php, ['-d', 'sys_temp_dir=.local/tmp', 'tests/Browser/E1-09/fixture.php'], options);
  const vitePort = await freePort();
  const appPort = await freePort();
  base = `http://127.0.0.1:${appPort}`;
  vite = spawn(process.execPath, ['node_modules/vite/bin/vite.js', '--host', '127.0.0.1', '--port', String(vitePort), '--strictPort'], { env: { ...process.env, APP_ENV: 'testing', LARAVEL_BYPASS_ENV_CHECK: '1' }, stdio: 'ignore' });
  await ready(`http://127.0.0.1:${vitePort}/@vite/client`);
  server = spawn(php, ['-S', `127.0.0.1:${appPort}`, '-t', 'public', 'tests/Browser/E1-01H/router.php'], { env: { ...process.env, APP_ENV: 'testing', APP_URL: base }, stdio: 'ignore' });
  await ready(base + '/login');
});
test.afterAll(async () => { server?.kill(); vite?.kill(); await unlink('public/hot').catch(() => {}); });
async function accessible(page: Page) {
  await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') });
  const violations = await page.evaluate(async () =>
    (
      await (
        window as unknown as {
          axe: {
            run: (context: Document, options: object) => Promise<{ violations: { id: string }[] }>;
          };
        }
      ).axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } })
    ).violations.map((item) => item.id),
  );
  expect(violations).toEqual([]);
}
test('journal uses real filtered data, shows details, and adapts to screen sizes', async ({
  page,
}) => {
  await page.goto(base + '/login');
  await page.getByLabel('Рабочая почта').fill('browser-auth@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/\/auth\/ready$/);
  await page.goto(base + '/journal');
  await expect(page.getByRole('heading', { name: 'Действия в кабинете' })).toBeVisible();
  await expect(page.getByText('journal-browser-fixture').first()).toBeVisible();
  await page.getByRole('button', { name: 'Действия с записью' }).first().click();
  await page.getByRole('button', { name: 'Детали действия', exact: true }).click();
  await expect(page.getByRole('dialog', { name: 'Детали действия' })).toContainText('черновик');
  await expect(page.getByRole('dialog', { name: 'Детали действия' })).toContainText('готово');
  await page.getByRole('button', { name: 'Закрыть' }).click();
  const shots = path.join(process.cwd(), '.local/screenshots/journal');
  await mkdir(shots, { recursive: true });
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(
      true,
    );
    await accessible(page);
    await page.screenshot({
      path: path.join(shots, 'actions-' + width + '.png'),
      fullPage: true,
      animations: 'disabled',
    });
  }
  await page.getByRole('combobox', { name: 'Период' }).click();
  await page.getByRole('option', { name: 'Свой диапазон' }).click();
  await page.getByLabel('С даты').fill('2100-01-01');
  await expect(page.getByText('По выбранным фильтрам действий нет')).toBeVisible();
  await page.getByRole('button', { name: 'Сбросить' }).click();
  await expect(page.getByText('journal-browser-fixture').last()).toBeVisible();
  await page.getByRole('link', { name: 'История уведомлений' }).click();
  await expect(page.getByRole('heading', { name: 'История уведомлений' })).toBeVisible();
  await expect(page.getByText('Диалог ждёт менеджера').last()).toBeVisible();
  await expect(page.getByRole('button', { name: 'Повторить' }).first()).toBeVisible();
  await accessible(page);
  await page.screenshot({
    path: path.join(shots, 'notifications-390.png'),
    fullPage: true,
    animations: 'disabled',
  });
  const gate = { release: () => {} };
  await page.route('**/journal/notifications?*', async (route) => {
    await new Promise<void>((resolve) => {
      gate.release = resolve;
    });
    await route.continue();
  });
  await page.getByLabel('Канал').click();
  await page.getByRole('option', { name: 'Email' }).click();
  await page.getByRole('button', { name: 'Применить' }).click();
  await expect(page.getByLabel('Загружается история уведомлений')).toBeVisible();
  gate.release();
  await expect(page.getByText('Диалог ждёт менеджера').last()).toBeVisible();
  await page.unroute('**/journal/notifications?*');
  await page.route('**/journal/notifications?*', (route) => route.abort());
  await page.getByLabel('Статус').click();
  await page.getByRole('option', { name: 'Ошибка' }).click();
  await page.getByRole('button', { name: 'Применить' }).click();
  await expect(page.getByRole('alert')).toContainText('Не удалось загрузить историю уведомлений');
  await page.unroute('**/journal/notifications?*');
  await page.getByRole('alert').locator('..').getByRole('button', { name: 'Повторить' }).click();
  await expect(page.getByText('Диалог ждёт менеджера').last()).toBeVisible();
  await expect(page).toHaveURL(/status=failed/);
  await expect(page.getByLabel('Статус')).toContainText('Ошибка');
});
