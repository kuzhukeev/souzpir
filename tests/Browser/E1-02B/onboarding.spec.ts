import { expect, test } from '@playwright/test';

const url = process.env.ONBOARDING_FIXTURE_URL || 'http://127.0.0.1:5183/tests/Browser/E1-02/ui-fixture.html';
test.beforeEach(async ({ page }) => {
  await page.route('**/build/**', route => route.fulfill({ path: `public${new URL(route.request().url()).pathname}` }));
});
test.use({ launchOptions: process.env.ONBOARDING_CHROMIUM_PATH ? { executablePath: process.env.ONBOARDING_CHROMIUM_PATH } : {}, viewport: { width: 1440, height: 900 } });

test('save and leave preserves draft and can return to the wizard', async ({ page }) => {
  await page.goto(url);
  const initialPage = await page.evaluate(() => JSON.parse(document.querySelector('script[data-page="app"]')!.textContent!));
  let saved: Record<string, unknown> = {};
  await page.route('**/start/draft', async route => {
    saved = route.request().postDataJSON();
    initialPage.props.initial = { ...saved, completed: false, already_configured: false };
    await route.fulfill({ status: 200, contentType: 'application/json', headers: { 'x-inertia': 'true' }, body: JSON.stringify(initialPage) });
  });
  await page.getByLabel('Название компании').fill('Новый бренд');
  await page.getByRole('button', { name: 'Сохранить и выйти' }).click();
  await expect(page.getByRole('heading', { name: 'Черновик сохранён' })).toBeVisible();
  expect(saved.organization_name).toBe('Новый бренд');
  await page.getByRole('button', { name: 'Вернуться к настройке' }).click();
  await expect(page.getByLabel('Название компании')).toHaveValue('Новый бренд');
  await page.screenshot({ path: '.local/screenshots/sp-e1-02b/company.png', fullPage: true });
});

test('module switches show selected count and tariff information', async ({ page }) => {
  await page.goto(url + '?step=3');
  const switches = page.getByRole('switch');
  await expect(switches).toHaveCount(2);
  await expect(page.getByText('Выбрано для настройки: 2')).toBeVisible();
  await switches.first().locator('xpath=ancestor::label').click();
  await expect(page.getByText('Выбрано для настройки: 1')).toBeVisible();
  await expect(page.getByText('Лимиты: — · По тарифу')).toHaveCount(2);
  await page.screenshot({ path: '.local/screenshots/sp-e1-02b/modules.png', fullPage: true });
});

test('last step can be postponed', async ({ page }) => {
  await page.goto(url + '?step=4');
  await expect(page.getByRole('button', { name: 'Закрыть и продолжить позже' })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e1-02b/final.png', fullPage: true });
});



