import { expect, test } from '@playwright/test';

test.skip(
  process.env.RUN_TEAM_LIST_E2E !== '1',
  'Requires the dedicated Team test server and synthetic fixture',
);
test.use({
  launchOptions: process.env.TEAM_CHROMIUM_PATH
    ? { executablePath: process.env.TEAM_CHROMIUM_PATH }
    : {},
});
const origin = 'http://127.0.0.1:8217';

test.beforeEach(async ({ page }) => {
  await page.route('**/*', (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
});

for (const role of ['owner', 'viewer']) {
  test(`${role} видит защищённый список участников`, async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`${origin}/login`);
    await page.getByLabel('Рабочая почта').fill(`team-e106-${role}@example.test`);
    await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
    await page.getByRole('button', { name: 'Войти', exact: true }).click();
    await expect(page).toHaveURL(/auth\/ready/);
    const response = await page.goto(`${origin}/team`);
    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Команда' })).toBeVisible();
    const table = page.getByRole('region', { name: 'Таблица участников' });
    await expect(table.getByText('Тестовый владелец')).toBeVisible();
    await expect(table.getByText('Тестовый наблюдатель')).toBeVisible();
    await expect(table.getByText('Пиротехника — Северные огни').first()).toBeVisible();
    if (role === 'viewer')
      await expect(page.getByRole('link', { name: 'Роли и доступ' })).toHaveCount(0);
    else await expect(page.getByRole('link', { name: 'Роли и доступ' }).first()).toBeVisible();
    await page.screenshot({
      path: `.local/screenshots/e1-06/team-list-real-${role}-1440.png`,
      fullPage: true,
    });
    await page.setViewportSize({ width: 390, height: 900 });
    await expect(page.locator('article').getByText('Тестовый наблюдатель')).toBeVisible();
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    ).toBeTruthy();
    await page.screenshot({
      path: `.local/screenshots/e1-06/team-list-real-${role}-390.png`,
      fullPage: true,
    });
  });
}
