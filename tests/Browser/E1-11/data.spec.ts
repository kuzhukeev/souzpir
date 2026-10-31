import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
import path from 'node:path';

const require = createRequire(import.meta.url);
test.setTimeout(90000);
test.beforeAll(() => {
  execFileSync(process.env.PHP_BINARY || 'php', ['-d', 'sys_temp_dir=.local/tmp', 'artisan', 'auth:test-browser-fixture', '--without-mfa', '--env=testing', '--no-ansi'], {
    cwd: process.cwd(), env: { ...process.env, APP_ENV: 'testing' }, stdio: 'pipe', timeout: 30000,
  });
});
async function accessible(page: Page) {
  await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') });
  const violations = await page.evaluate(async () => (await (window as unknown as { axe: { run: (context: Document, options: object) => Promise<{ violations: { id: string }[] }> } }).axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } })).violations.map((item) => item.id));
  expect(violations).toEqual([]);
}
test('consents and unavailable export state are accessible at supported widths', async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel('Рабочая почта').fill('browser-auth@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/\/auth\/ready$/);
  await page.goto('/settings/data');
  await expect(page.getByRole('heading', { name: 'Данные и согласия' })).toBeVisible();
  await expect(page.getByText('Экспорт данных модулей пока недоступен: ни один раздел не подключён.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Подготовить архив' })).toBeDisabled();
  const shots = path.join(process.cwd(), '.local/screenshots/e1-11');
  await mkdir(shots, { recursive: true });
  for (const theme of ['neutral', 'pyro', 'confectionery']) {
    // Visual CSS coverage only; persistence is exercised by the brand settings tests.
    await page.evaluate((value) => { document.documentElement.dataset.theme = value; }, theme);
    await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
    for (const width of [1440, 1024, 390]) {
      await page.setViewportSize({ width, height: 900 });
      await expect(page.getByRole('heading', { name: 'Данные и согласия' })).toBeVisible();
      await expect(page.getByRole('heading', { name: 'Согласия', exact: true })).toBeVisible();
      await expect(page.getByRole('heading', { name: 'Экспорт данных', exact: true })).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
      await accessible(page);
      await page.screenshot({ path: path.join(shots, `data-${theme}-${width}.png`), fullPage: true, animations: 'disabled' });
    }
  }
});