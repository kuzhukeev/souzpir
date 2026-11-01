import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

test.skip(
  process.env.RUN_TEAM_LAST_LOGIN_E2E !== '1' || !process.env.TEAM_PHP_PATH,
  'Requires the isolated Team PostgreSQL fixture and PHP',
);
test.use({
  launchOptions: process.env.TEAM_CHROMIUM_PATH
    ? { executablePath: process.env.TEAM_CHROMIUM_PATH }
    : {},
});
const origin = 'http://127.0.0.1:8217';

async function login(page: Page, role: 'owner' | 'viewer') {
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill(`team-e106-${role}@example.test`);
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
}

function schema(action: 'hide' | 'restore') {
  execFileSync(process.env.TEAM_PHP_PATH!, ['tests/Browser/E1-06/prepare-fixture.php'], {
    env: { ...process.env, E106_LAST_LOGIN_SCHEMA: action },
    encoding: 'utf8',
  });
}

function access(action: 'disable' | 'restore') {
  execFileSync(process.env.TEAM_PHP_PATH!, ['tests/Browser/E1-06/prepare-fixture.php'], {
    env: { ...process.env, E106_LAST_LOGIN_ACCESS: action },
    encoding: 'utf8',
  });
}

test('отключение доступа скрывает ранее показанное время при следующей проверке', async ({
  page,
  context,
  browser,
}) => {
  const ownerContext = await browser.newContext();
  try {
    await ownerContext.route('**/*', (route) =>
      new URL(route.request().url()).origin === origin
        ? route.continue()
        : route.abort('blockedbyclient'),
    );
    await login(await ownerContext.newPage(), 'owner');
  } finally {
    await ownerContext.close();
  }
  await context.route('**/*', (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
  await login(page, 'viewer');
  await page.goto(`${origin}/team`);
  const owner = page
    .getByRole('region', { name: 'Таблица участников' })
    .getByRole('row')
    .filter({ hasText: 'Тестовый владелец' });
  await expect(owner.locator('time')).toBeVisible();
  access('disable');
  try {
    await expect(owner.locator('time')).toBeHidden({ timeout: 12_000 });
    await expect(owner.getByText('Недоступно')).toBeVisible();
  } finally {
    access('restore');
  }
});

test('настоящий вход, список, смена бренда и ограниченный доступ', async ({
  page,
  context,
  browser,
}) => {
  await context.route('**/*', (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page, 'owner');
  await page.goto(`${origin}/team`);
  const table = page.getByRole('region', { name: 'Таблица участников' });
  const owner = table.getByRole('row').filter({ hasText: 'Тестовый владелец' });
  const disabled = table.getByRole('row').filter({ hasText: 'Тестовый отключённый' });
  await expect(owner.locator('time')).toBeVisible();
  await expect(disabled.getByText('Нет данных')).toBeVisible();
  const props = await page.evaluate(
    () =>
      JSON.parse(document.querySelector('script[data-page="app"]')?.textContent || '{}').props as {
        brand: { id: string };
        brands: { id: string; name: string }[];
        teamBrandRevision: string;
        members: { data: { id: string }[] };
      },
  );
  const a = props.brands.find((item) => item.name === 'Пиротехника — Северные огни')!;
  const b = props.brands.find((item) => item.name === 'Фейерверки — Второй бренд')!;
  expect(props.brand.id).toBe(a.id);
  await page.screenshot({
    path: '.local/screenshots/e1-06/last-login-real-desktop.png',
    fullPage: true,
  });
  await page.setViewportSize({ width: 390, height: 900 });
  const ownerCard = page.locator('article').filter({ hasText: 'Тестовый владелец' });
  const disabledCard = page.locator('article').filter({ hasText: 'Тестовый отключённый' });
  await expect(ownerCard.getByText('Последний вход')).toBeVisible();
  await expect(ownerCard.locator('time')).toBeVisible();
  await expect(disabledCard.getByText('Нет данных')).toBeVisible();
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
  ).toBeTruthy();
  await page.screenshot({
    path: '.local/screenshots/e1-06/last-login-real-mobile.png',
    fullPage: true,
  });

  const other = await context.newPage();
  await other.goto(`${origin}/brands/${a.id}/settings`);
  await other.getByRole('combobox', { name: 'Выбрать бренд' }).click();
  await other.getByRole('option', { name: b.name }).click();
  await expect(other).toHaveURL(/auth\/ready/);
  await expect(ownerCard.locator('time')).toBeHidden();
  const stale = await page.request.get(
    `${origin}/team/member-last-logins?ids[]=${props.members.data[0].id}`,
    {
      headers: {
        'X-Brand-Id': a.id,
        'X-Brand-Revision': props.teamBrandRevision,
        Accept: 'application/json',
      },
    },
  );
  expect([404, 409]).toContain(stale.status());
  await page.bringToFront();
  await page.reload();
  await expect(ownerCard.locator('time')).toBeVisible();
  const current = await page.evaluate(
    () =>
      JSON.parse(document.querySelector('script[data-page="app"]')?.textContent || '{}').props.brand
        .id,
  );
  expect(current).toBe(b.id);

  const viewerContext = await browser.newContext();
  try {
    await viewerContext.route('**/*', (route) =>
      new URL(route.request().url()).origin === origin
        ? route.continue()
        : route.abort('blockedbyclient'),
    );
    const viewer = await viewerContext.newPage();
    await login(viewer, 'viewer');
    await viewer.goto(`${origin}/team`);
    const visibleBrands = await viewer.evaluate(
      () =>
        JSON.parse(document.querySelector('script[data-page="app"]')?.textContent || '{}').props
          .brands as { id: string }[],
    );
    expect(visibleBrands.map((brand) => brand.id)).not.toContain(b.id);
    expect([403, 404]).toContain(
      (await viewer.request.get(`${origin}/brands/${b.id}/settings`)).status(),
    );
  } finally {
    await viewerContext.close();
  }
});

test('реальная отсутствующая таблица показывает недоступность и позволяет повторить после восстановления', async ({
  page,
  context,
}) => {
  await context.route('**/*', (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
  await login(page, 'owner');
  schema('hide');
  try {
    await page.goto(`${origin}/team`);
    await expect(page.getByText('Не удалось загрузить время последнего входа.')).toBeVisible();
    const table = page.getByRole('region', { name: 'Таблица участников' });
    await expect(
      table.getByRole('row').filter({ hasText: 'Тестовый владелец' }).getByText('Недоступно'),
    ).toBeVisible();
    await expect(
      table.getByRole('row').filter({ hasText: 'Тестовый отключённый' }).getByText('Недоступно'),
    ).toBeVisible();
    await page.screenshot({
      path: '.local/screenshots/e1-06/last-login-unavailable-real.png',
      fullPage: true,
    });
  } finally {
    schema('restore');
  }
  await page.getByRole('button', { name: 'Повторить' }).click();
  const table = page.getByRole('region', { name: 'Таблица участников' });
  await expect(
    table.getByRole('row').filter({ hasText: 'Тестовый владелец' }).locator('time'),
  ).toBeVisible();
  await expect(
    table.getByRole('row').filter({ hasText: 'Тестовый отключённый' }).getByText('Нет данных'),
  ).toBeVisible();
});
