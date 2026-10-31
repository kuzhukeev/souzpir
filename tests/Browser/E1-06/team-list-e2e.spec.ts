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
    expect(await response?.text()).not.toContain('token_hash');
    await expect(page.getByRole('heading', { level: 1, name: 'Команда' })).toBeVisible();
    const table = page.getByRole('region', { name: 'Таблица участников' });
    await expect(table.getByText('Тестовый владелец')).toBeVisible();
    await expect(table.getByText('Тестовый наблюдатель')).toBeVisible();
    await expect(table.getByText('Пиротехника — Северные огни').first()).toBeVisible();
    const invitations = page.getByRole('region', { name: 'Приглашения в ожидании' });
    await expect(invitations.getByText('team-e106-invite@example.test')).toBeVisible();
    await expect(invitations.getByText('Ссылка не открыта')).toBeVisible();
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

test('фильтр ролей меняет оба списка и поддерживает клавиатуру', async ({ page }) => {
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill('team-e106-owner@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  await page.goto(`${origin}/team?page=2&invitation_page=2`);
  const filter = page.getByRole('combobox', { name: 'Роль', exact: true });
  await filter.focus();
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('End');
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(/role=viewer/);
  await expect(page).toHaveURL(/page=1/);
  await expect(filter).toContainText('Наблюдатель');
  await expect(
    page.getByRole('region', { name: 'Таблица участников' }).getByText('Тестовый наблюдатель'),
  ).toBeVisible();
  await expect(
    page.getByRole('region', { name: 'Таблица участников' }).getByText('Тестовый владелец'),
  ).toHaveCount(0);
  await expect(
    page
      .getByRole('region', { name: 'Приглашения в ожидании' })
      .getByText('team-e106-invite@example.test'),
  ).toBeVisible();
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    ).toBeTruthy();
    await page.screenshot({
      path: `.local/screenshots/e1-06/role-filter-real-${width}.png`,
      fullPage: true,
    });
  }
  await filter.click();
  await page.getByRole('option', { name: 'Менеджер' }).click();
  await expect(page.getByText('Участников с этой ролью нет')).toBeVisible();
  await expect(page.getByText('Приглашений с этой ролью нет')).toBeVisible();
  await filter.click();
  await page.getByRole('option', { name: 'Все роли' }).click();
  await expect(page.locator('article').getByText('Тестовый владелец')).toBeVisible();
});
