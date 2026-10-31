import { expect, test } from '@playwright/test';

test.skip(
  process.env.RUN_TEAM_REVISION_E2E !== '1',
  'Requires the dedicated revision test server and synthetic fixture',
);
test.use({
  launchOptions: process.env.TEAM_CHROMIUM_PATH
    ? { executablePath: process.env.TEAM_CHROMIUM_PATH }
    : {},
});
const origin = 'http://127.0.0.1:8219';

test('старая вкладка не может отозвать приглашение после переключения A → B → A', async ({
  page,
  context,
}) => {
  await context.route('**/*', (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill('team-e106-owner@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  await page.goto(`${origin}/team`);
  const props = await page.evaluate(
    () =>
      JSON.parse(document.querySelector('script[data-page="app"]')?.textContent || '{}').props as {
        brand: { id: string };
        brands: { id: string; name: string }[];
      },
  );
  const a = props.brands.find((brand) => brand.name === 'Пиротехника — Северные огни');
  const b = props.brands.find((brand) => brand.name === 'Фейерверки — Второй бренд');
  expect(a).toBeTruthy();
  expect(b).toBeTruthy();
  expect(props.brand.id).toBe(a?.id);
  const list = page.getByRole('region', { name: 'Приглашения в ожидании' });
  await list.getByRole('button', { name: 'Отозвать' }).click();
  const modal = page.getByRole('dialog', { name: 'Отозвать приглашение' });
  await expect(modal).toContainText('team-e106-invite@example.test');
  const other = await context.newPage();
  await other.goto(`${origin}/brands/${a!.id}/settings`);
  await other.getByRole('combobox', { name: 'Выбрать бренд' }).click();
  await other.getByRole('option', { name: b!.name }).click();
  await expect(other).toHaveURL(/auth\/ready/);
  await other.goto(`${origin}/brands/${b!.id}/settings`);
  await other.getByRole('combobox', { name: 'Выбрать бренд' }).click();
  await other.getByRole('option', { name: a!.name }).click();
  await expect(other).toHaveURL(/auth\/ready/);
  const result = page.waitForResponse(
    (response) =>
      response.request().method() === 'POST' &&
      /\/team\/invitations\/[^/]+\/revoke$/.test(new URL(response.url()).pathname),
  );
  await modal.getByRole('button', { name: 'Отозвать приглашение' }).click();
  expect((await result).status()).toBe(409);
  await expect(modal.getByRole('alert')).toContainText('Обновите страницу');
  await page.reload();
  await expect(page.getByText('team-e106-invite@example.test')).toBeVisible();
});
