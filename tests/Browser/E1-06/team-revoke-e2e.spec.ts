import { expect, test } from '@playwright/test';

test.skip(
  process.env.RUN_TEAM_REVOKE_E2E !== '1',
  'Requires dedicated test server and synthetic fixture',
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
for (const role of ['viewer', 'owner']) {
  test(`${role}: действие отзыва в настоящем приложении`, async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`${origin}/login`);
    await page.getByLabel('Рабочая почта').fill(`team-e106-${role}@example.test`);
    await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
    await page.getByRole('button', { name: 'Войти', exact: true }).click();
    await expect(page).toHaveURL(/auth\/ready/);
    await page.goto(`${origin}/team`);
    const list = page.getByRole('region', { name: 'Приглашения в ожидании' });
    await expect(list.getByText('team-e106-invite@example.test')).toBeVisible();
    if (role === 'viewer') {
      await expect(list.getByRole('button', { name: 'Отозвать' })).toHaveCount(0);
      return;
    }
    const revoke = list.getByRole('button', { name: 'Отозвать' });
    await revoke.click();
    let modal = page.getByRole('dialog', { name: 'Отозвать приглашение' });
    await expect(modal).toContainText('team-e106-invite@example.test');
    await page.screenshot({
      path: '.local/screenshots/e1-06/team-revoke-real-1440.png',
      fullPage: true,
    });
    await page.setViewportSize({ width: 390, height: 900 });
    await page.screenshot({
      path: '.local/screenshots/e1-06/team-revoke-real-390.png',
      fullPage: true,
    });
    await page.setViewportSize({ width: 1440, height: 900 });
    await modal.getByRole('button', { name: 'Отмена' }).click();
    await expect(list.getByText('team-e106-invite@example.test')).toBeVisible();
    await revoke.click();
    modal = page.getByRole('dialog', { name: 'Отозвать приглашение' });
    await modal.getByRole('button', { name: 'Отозвать приглашение' }).click();
    await expect(list.getByText('team-e106-invite@example.test')).toHaveCount(0);
    await page.reload();
    await expect(page.getByText('Приглашений в ожидании нет')).toBeVisible();
  });
}
