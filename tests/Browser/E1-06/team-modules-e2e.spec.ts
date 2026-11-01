import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

test.skip(process.env.RUN_TEAM_LIST_E2E !== '1' || !process.env.TEAM_PHP_PATH, 'Requires isolated Team fixture');
test.use({ launchOptions: process.env.TEAM_CHROMIUM_PATH ? { executablePath: process.env.TEAM_CHROMIUM_PATH } : {} });
const origin = 'http://127.0.0.1:8217';

test('список и карточка показывают модули общих брендов, не раскрывая недоступные бренды', async ({ page }) => {
  await page.route('**/*', (route) => new URL(route.request().url()).origin === origin ? route.continue() : route.abort('blockedbyclient'));
  execFileSync(process.env.TEAM_PHP_PATH!, ['tests/Browser/E1-06/prepare-fixture.php'], {
    env: { ...process.env, E106_SHARED_MODULES: '1' }, encoding: 'utf8',
  });
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill('team-e106-viewer@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  await page.goto(`${origin}/team`);
  const table = page.getByRole('region', { name: 'Таблица участников' });
  const owner = table.getByRole('row').filter({ hasText: 'Тестовый владелец' });
  await expect(owner.getByRole('cell').nth(2)).toContainText('Продавец');
  await expect(owner.getByText('Фейерверки — Второй бренд')).toHaveCount(0);
  await expect(owner.getByText('Видение', { exact: true })).toHaveCount(0);
  await owner.getByRole('link', { name: /Открыть карточку/ }).click();
  const access = page.getByRole('region', { name: 'Роль и доступ' });
  await expect(access).toContainText('Продавец');
  await expect(access.getByText('Фейерверки — Второй бренд')).toHaveCount(0);
  await expect(access.getByText(/Действия участника/)).toBeVisible();
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    await page.screenshot({ path: `.local/screenshots/e1-06/team-modules-real-${width}.png`, fullPage: true });
  }
});
