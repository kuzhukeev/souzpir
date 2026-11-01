import { expect, test, type Page } from '@playwright/test';

test.skip(
  process.env.RUN_TEAM_INVITATION_E2E !== '1',
  'Requires the isolated Team test server and synthetic fixture',
);
test.use({
  trace: 'off',
  screenshot: 'off',
  video: 'off',
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
async function login(page: Page, role: 'owner' | 'viewer') {
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill(`team-e106-${role}@example.test`);
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  await page.goto(`${origin}/team`);
}
async function prepare(page: Page, email: string) {
  await page.getByRole('button', { name: 'Пригласить участника' }).click();
  const dialog = page.getByRole('dialog', { name: 'Пригласить участника' });
  await dialog.getByLabel('Электронная почта').fill(email);
  await dialog.getByRole('button', { name: 'Продолжить' }).click();
  await expect(dialog.getByText('Письмо автоматически не отправляется.')).toBeVisible();
}

test('владелец открывает реальную форму, выпускает ссылку и видит приглашение в списке', async ({
  page,
}) => {
  const email = `e106-team-${Date.now()}@example.test`;
  await login(page, 'owner');
  await page.getByRole('button', { name: 'Пригласить участника' }).click();
  const dialog = page.getByRole('dialog', { name: 'Пригласить участника' });
  await expect(dialog.getByLabel('Электронная почта')).toBeVisible();
  await dialog.getByRole('button', { name: 'Закрыть' }).click();
  await expect(dialog).toHaveCount(0);
  await prepare(page, email);
  const checked = page.waitForResponse(
    (response) =>
      response.request().method() === 'GET' &&
      response.url() === `${origin}/team` &&
      response.request().headers()['x-inertia'] === 'true',
    { timeout: 5000 },
  );
  const posted = page.waitForResponse(
    (response) =>
      response.request().method() === 'POST' && response.url() === `${origin}/team/invitations`,
  );
  await dialog.getByRole('button', { name: 'Подтвердить выпуск' }).click();
  const issuance = await posted;
  expect(issuance.status()).toBe(201);
  const rechecked = await checked;
  expect(rechecked.status()).toBe(200);
  const context = (await rechecked.json()).props as {
    teamBrandRevision: string;
    canViewRoles: boolean;
    brand: { id: string };
    filterBrands: { id: string }[];
  };
  expect(context.canViewRoles).toBe(true);
  expect(context.filterBrands.some((brand) => brand.id === context.brand.id)).toBe(true);
  try {
    await expect(dialog.getByLabel('Ссылка приглашения')).toBeVisible();
  } finally {
    // Erase the one-time bearer before Playwright can record a failure snapshot.
    try {
      await dialog.getByRole('button', { name: 'Закрыть' }).click({ timeout: 1500 });
    } catch {
      await page.goto('about:blank');
    }
  }
  await expect(dialog.getByLabel('Ссылка приглашения')).toHaveCount(0);
  await page.reload();
  await expect(
    page.getByRole('region', { name: 'Приглашения в ожидании' }).getByText(email),
  ).toBeVisible();
});

test('наблюдатель не видит кнопку, прямой запрос запрещён сервером', async ({ page }) => {
  await login(page, 'viewer');
  await expect(page.getByRole('button', { name: 'Пригласить участника' })).toHaveCount(0);
  const status = await page.evaluate(async () => {
    const props = JSON.parse(
      document.querySelector('script[data-page="app"]')?.textContent || '{}',
    ).props;
    const token = decodeURIComponent(
      document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length) || '',
    );
    const response = await fetch('/team/invitations', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': token,
        'X-Brand-Revision': props.teamBrandRevision,
      },
      body: JSON.stringify({
        attempt_key: 'a'.repeat(64),
        email: 'viewer-denied@example.test',
        role: 'viewer',
        sales_manager: false,
        brand_ids: [props.brand.id],
      }),
    });
    return response.status;
  });
  expect(status).toBe(403);
});

test('закрытие после фиксации, но до ответа сохраняет предупреждение без повторного POST', async ({
  page,
}) => {
  const email = `e106-pending-${Date.now()}@example.test`;
  await login(page, 'owner');
  let release!: () => void;
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  let entered!: (status: number) => void;
  const committed = new Promise<number>((resolve) => {
    entered = resolve;
  });
  let posts = 0;
  await page.route('**/team/invitations', async (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    posts += 1;
    const response = await route.fetch();
    entered(response.status());
    await gate;
    try {
      await route.fulfill({ response });
    } catch {
      /* Closing aborts the browser request. */
    }
  });
  try {
    await prepare(page, email);
    await page.getByRole('button', { name: 'Подтвердить выпуск' }).click();
    expect(await committed).toBe(201);
    await page.getByRole('dialog').getByRole('button', { name: 'Закрыть' }).click();
    release();
    await page.getByRole('button', { name: 'Пригласить участника' }).click();
    const dialog = page.getByRole('dialog', { name: 'Пригласить участника' });
    await expect(dialog.getByText(/Результат неизвестен/)).toBeVisible();
    await expect(dialog.getByLabel('Ссылка приглашения')).toHaveCount(0);
    expect(posts).toBe(1);
  } finally {
    release();
  }
});
