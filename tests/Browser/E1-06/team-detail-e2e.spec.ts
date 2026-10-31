import { expect, test } from '@playwright/test';

test.skip(
  process.env.RUN_TEAM_DETAIL_E2E !== '1',
  'Requires the dedicated Team test server and synthetic fixture',
);
test.use({
  launchOptions: process.env.TEAM_CHROMIUM_PATH
    ? { executablePath: process.env.TEAM_CHROMIUM_PATH }
    : {},
});
const origin = 'http://127.0.0.1:8218';
test.beforeEach(async ({ page }) => {
  await page.route('**/*', (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
});
for (const role of ['owner', 'viewer']) {
  test(`${role} открывает реальную карточку участника из списка`, async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`${origin}/login`);
    await page.getByLabel('Рабочая почта').fill(`team-e106-${role}@example.test`);
    await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
    await page.getByRole('button', { name: 'Войти', exact: true }).click();
    await expect(page).toHaveURL(/auth\/ready/);
    await page.goto(`${origin}/team`);
    await page
      .getByRole('region', { name: 'Таблица участников' })
      .getByRole('link', { name: 'Открыть карточку: Тестовый наблюдатель' })
      .click();
    await expect(page).toHaveURL(/\/team\/[0-9A-HJKMNP-TV-Z]{26}$/i);
    await expect(
      page.getByRole('heading', { level: 1, name: 'Тестовый наблюдатель' }),
    ).toBeVisible();
    await expect(page.getByRole('region', { name: 'Роль и доступ' })).toContainText('Наблюдатель');
    await expect(page.getByRole('region', { name: 'Контакты' })).toContainText('Не указан');
    await expect(page.getByRole('button', { name: /сохранить|отключить|пригласить/i })).toHaveCount(
      0,
    );
    await expect(page.locator('#nprogress')).toHaveCount(0);
    await page.screenshot({
      path: `.local/screenshots/e1-06/team-detail-real-${role}-1440.png`,
      fullPage: true,
    });
    await page.setViewportSize({ width: 390, height: 900 });
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    ).toBeTruthy();
    await page.screenshot({
      path: `.local/screenshots/e1-06/team-detail-real-${role}-390.png`,
      fullPage: true,
    });
    await page.getByRole('link', { name: 'К участникам' }).click();
    await expect(page).toHaveURL(/\/team$/);
  });
}
