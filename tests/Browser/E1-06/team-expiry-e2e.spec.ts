import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

test.skip(
  process.env.RUN_TEAM_LIST_E2E !== '1' || !process.env.TEAM_PHP_PATH,
  'Requires the isolated Team fixture and PHP',
);
test.use({
  launchOptions: process.env.TEAM_CHROMIUM_PATH
    ? { executablePath: process.env.TEAM_CHROMIUM_PATH }
    : {},
});
const origin = 'http://127.0.0.1:8217';

test('открытое приглашение истекает, отзыв отключается, а обновлённый список его скрывает', async ({
  page,
}) => {
  await page.route('**/*', (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill('team-e106-owner@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);

  const output = execFileSync(
    process.env.TEAM_PHP_PATH!,
    ['tests/Browser/E1-06/prepare-fixture.php'],
    {
      encoding: 'utf8',
      env: { ...process.env, E106_EXPIRING_INVITATION: '1' },
    },
  );
  const { email } = JSON.parse(output) as { email: string };
  await page.goto(`${origin}/team?role=viewer&status=invited`);
  const invitation = page.getByRole('listitem').filter({ hasText: email });
  await expect(invitation.getByText('Осталось менее суток')).toBeVisible();
  await invitation.getByRole('button', { name: 'Отозвать', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Отозвать приглашение' });
  const confirm = dialog.getByRole('button', { name: 'Отозвать приглашение' });
  await expect(confirm).toBeEnabled();
  await expect(confirm).toBeDisabled({ timeout: 20_000 });
  await expect(dialog.getByRole('alert')).toContainText('Срок действия приглашения истёк');
  await dialog.getByRole('button', { name: 'Отмена' }).click();
  await expect(invitation.getByText('Срок истёк')).toBeVisible();
  await expect(invitation.getByRole('button', { name: 'Отозвать', exact: true })).toBeDisabled();
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await expect(invitation.getByText('Срок истёк')).toBeVisible();
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    ).toBeTruthy();
    await page.screenshot({
      path: `.local/screenshots/e1-06/invitation-expired-real-${width}.png`,
      fullPage: true,
    });
  }
  await page.reload();
  await expect(page.getByText(email)).toHaveCount(0);
});
