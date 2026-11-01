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

test.beforeEach(async ({ page }) => {
  await page.route('**/*', (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
});

test('владелец переходит из карточки в настоящий журнал с фильтром пользователя и бренда', async ({
  page,
}) => {
  const output = execFileSync(
    process.env.TEAM_PHP_PATH!,
    ['tests/Browser/E1-06/prepare-fixture.php'],
    {
      encoding: 'utf8',
      env: { ...process.env, E106_ACTIVITY_FIXTURE: '1' },
    },
  );
  const { actor, tag } = JSON.parse(output) as { actor: string; tag: string };
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill('team-e106-owner@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  await page.goto(`${origin}/team`);
  await page
    .getByRole('region', { name: 'Таблица участников' })
    .getByRole('link', { name: 'Открыть карточку: Тестовый наблюдатель' })
    .click();
  const link = page.getByRole('link', { name: 'Посмотреть активность участника' });
  await expect(link).toHaveAttribute('href', `/journal?actor=${actor}`);
  await expect(page.getByRole('region', { name: 'Активность' })).toContainText(
    'только текущего бренда и общие действия организации',
  );
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    ).toBeTruthy();
    await page.screenshot({
      path: `.local/screenshots/e1-06/team-activity-real-${width}.png`,
      fullPage: true,
    });
  }
  await link.click();
  await expect(page).toHaveURL(`${origin}/journal?actor=${actor}`);
  await expect(page.getByRole('combobox', { name: 'Участник' })).toContainText(
    'Тестовый наблюдатель',
  );
  const mobileRows = page.locator('div[aria-label="Действия в кабинете"]');
  await expect(mobileRows.getByText(`activity-target-${tag}`)).toBeVisible();
  await expect(mobileRows.getByText(`activity-global-${tag}`)).toBeVisible();
  await expect(page.getByText(`activity-other-brand-${tag}`)).toHaveCount(0);
  await expect(page.getByText(`activity-other-actor-${tag}`)).toHaveCount(0);
});

test('наблюдатель не видит ссылку и не получает доступ к журналу', async ({ page }) => {
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill('team-e106-viewer@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  await page.goto(`${origin}/team`);
  await page
    .getByRole('region', { name: 'Таблица участников' })
    .getByRole('link', { name: 'Открыть карточку: Тестовый наблюдатель' })
    .click();
  await expect(page.getByRole('link', { name: 'Посмотреть активность участника' })).toHaveCount(0);
  expect((await page.request.get(`${origin}/journal`)).status()).toBe(403);
});

test('участник без записей открывает пустой журнал с фильтром', async ({ page }) => {
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill('team-e106-owner@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  await page.goto(`${origin}/team`);
  await page
    .getByRole('region', { name: 'Таблица участников' })
    .getByRole('link', { name: 'Открыть карточку: Тестовый отключённый' })
    .click();
  const link = page.getByRole('link', { name: 'Посмотреть активность участника' });
  const destination = await link.getAttribute('href');
  expect(destination).toMatch(/^\/journal\?actor=[0-9A-HJKMNP-TV-Z]{26}$/i);
  await link.click();
  await expect(page).toHaveURL(`${origin}${destination}`);
  await expect(page.getByText('Действий пока нет')).toBeVisible();
  await page.screenshot({
    path: '.local/screenshots/e1-06/team-activity-empty-real.png',
    fullPage: true,
  });
});
