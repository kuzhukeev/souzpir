import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
import path from 'node:path';

test.skip(process.env.RUN_SITE_E2E !== '1', 'Requires an isolated site test server and database');
test.use({
  launchOptions: process.env.SITE_CHROMIUM_PATH
    ? { executablePath: process.env.SITE_CHROMIUM_PATH }
    : {},
});
test.setTimeout(180000);
const base = process.env.SITE_BASE_URL || 'http://127.0.0.1:8206';
const require = createRequire(import.meta.url);
const php = process.env.PHP_BINARY || 'php';
const options = {
  cwd: process.cwd(),
  env: { ...process.env, APP_ENV: 'testing' },
  stdio: 'pipe' as const,
  timeout: 30000,
};
function fixture(mode: string) {
  return execFileSync(
    php,
    ['-d', 'sys_temp_dir=.local/tmp', 'tests/Browser/E8-06/fixture.php', mode],
    options,
  ).toString();
}
test.beforeAll(() => {
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
  fixture('setup');
});
test.beforeEach(async ({ page }) => {
  await page.route('**/*', (route) =>
    new URL(route.request().url()).origin === new URL(base).origin
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
});
async function accessible(page: Page) {
  await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') });
  const violations = await page.evaluate(async () =>
    (
      await (
        window as unknown as {
          axe: {
            run: (root: Document, options: object) => Promise<{ violations: { id: string }[] }>;
          };
        }
      ).axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } })
    ).violations.map((item) => item.id),
  );
  expect(violations).toEqual([]);
}
async function login(page: Page) {
  await page.goto(base + '/login');
  await page.getByLabel('Рабочая почта').fill('browser-auth@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/\/auth\/ready$/);
}
test('real site audit and actions render at all widths and themes', async ({ page }) => {
  await login(page);
  await page.goto(base + '/vision/site');
  await expect(page.getByRole('heading', { name: 'Готовность сайта к ИИ-поиску' })).toBeVisible();
  const shots = path.join(process.cwd(), '.local/screenshots/e8-06');
  await mkdir(shots, { recursive: true });
  for (const theme of ['neutral', 'pyro', 'confectionery']) {
    fixture('theme:' + theme);
    for (const width of [1440, 1024, 390]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(base + '/vision/site');
      await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
      ).toBe(true);
      await accessible(page);
      await page.screenshot({
        path: path.join(shots, `site-${theme}-${width}.png`),
        fullPage: true,
        animations: 'disabled',
      });
      await page.goto(base + '/vision/actions');
      await expect(page.getByText('SP-E8-06: Проверить страницу')).toBeVisible();
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
      ).toBe(true);
      await accessible(page);
      await page.screenshot({
        path: path.join(shots, `actions-${theme}-${width}.png`),
        fullPage: true,
        animations: 'disabled',
      });
    }
  }
  await page.goto(base + '/vision/actions/history');
  await expect(page.getByText('SP-E8-06: Обновить сведения')).toBeVisible();
  await expect(
    page
      .getByRole('article')
      .filter({ hasText: 'SP-E8-06: Обновить сведения' })
      .getByText('Ждём замера'),
  ).toBeVisible();
  await page.screenshot({
    path: path.join(shots, 'action-history-390.png'),
    fullPage: true,
    animations: 'disabled',
  });
});
test('action creation and completion persist through the server', async ({ page }) => {
  test.skip(
    process.env.SITE_WRITE_E2E !== '1',
    'Requires the integrated vision.actions.write permission',
  );
  const title = 'SP-E8-06: Новое действие ' + Date.now();
  await login(page);
  await page.goto(base + '/vision/actions');
  await page.getByText('Добавить действие', { exact: true }).click();
  await page.getByLabel('Название', { exact: true }).fill(title);
  await page.getByLabel('Описание', { exact: true }).fill('Обновить сведения');
  await page.getByRole('button', { name: 'Добавить', exact: true }).click();
  const action = page.getByRole('article').filter({ hasText: title }).first();
  await expect(action).toBeVisible();
  await action.getByRole('button', { name: 'Отметить выполненным' }).click();
  const dialog = page.getByRole('dialog', { name: 'Отметить выполненным' });
  await dialog.getByLabel('Дата выполнения').fill('2026-10-31');
  await dialog.getByLabel('Что сделано').fill('Сведения обновлены');
  await dialog.getByRole('button', { name: 'Отметить выполненным' }).click();
  await page.goto(base + '/vision/actions/history');
  await expect(page.getByRole('article').filter({ hasText: title })).toBeVisible();
  await page.reload();
  await expect(
    page
      .getByRole('article')
      .filter({ hasText: title })
      .getByText('Комментарий: Сведения обновлены'),
  ).toBeVisible();
  await expect(page.getByText(/Рано судить: порядок и сопоставимость/)).toBeVisible();
});
