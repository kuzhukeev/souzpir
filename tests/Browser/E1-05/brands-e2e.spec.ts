import { test, expect } from '@playwright/test';
test.use({
  launchOptions: process.env.BRANDS_CHROMIUM_PATH
    ? { executablePath: process.env.BRANDS_CHROMIUM_PATH }
    : {},
});
test.skip(
  process.env.RUN_BRANDS_E2E !== '1',
  'Requires the dedicated brands test server and fixture',
);
const base = process.env.BRANDS_BASE_URL || 'http://127.0.0.1:8215';
const settings = base + '/brands/01J00000000000000000000004/settings';
test.beforeEach(async ({ page }) => {
  await page.route('**/*', (route) => {
    const target = new URL(route.request().url());
    return target.origin === 'http://127.0.0.1:8215'
      ? route.continue()
      : route.abort('blockedbyclient');
  });
});
test('owner saves settings and draft alias twice, changes theme, manages a domain and reuses module settings', async ({
  page,
}) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(base + '/login');
  await page.getByLabel('Рабочая почта').fill('brands-e105-owner@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  await page.goto(settings);
  await page.getByRole('textbox', { name: 'Адрес сайта', exact: true }).fill('http://127.0.0.1');
  await page.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('публичного сайта');
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({
    path: '.local/screenshots/e1-05/real-validation-error.png',
    fullPage: true,
  });
  await page.getByRole('textbox', { name: 'Адрес сайта', exact: true }).fill('');
  await page
    .getByRole('textbox', { name: 'Название бренда', exact: true })
    .fill('Бренд после сохранения');
  await page.getByRole('textbox', { name: 'Добавить алиас', exact: true }).fill('Алиас без Enter');
  await page.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
  await expect(
    page.getByRole('button', { name: 'Удалить алиас Алиас без Enter', exact: true }),
  ).toBeVisible();
  await page.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
  await expect(
    page.getByRole('button', { name: 'Сохранить изменения', exact: true }),
  ).toBeEnabled();
  await page.reload();
  await expect(page.getByRole('textbox', { name: 'Название бренда', exact: true })).toHaveValue(
    'Бренд после сохранения',
  );
  await expect(
    page.getByRole('button', { name: 'Удалить алиас Алиас без Enter', exact: true }),
  ).toBeVisible();
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({
    path: '.local/screenshots/e1-05/real-general-saved.png',
    fullPage: true,
  });
  await page.getByRole('tab', { name: 'Оформление кабинета', exact: true }).click();
  await page.getByRole('radio', { name: /^Пиротехника/ }).check();
  await page
    .getByRole('textbox', { name: 'Подпись под логотипом', exact: true })
    .fill('Проверено в браузере');
  await page.getByRole('switch', { name: 'Показывать промо-карту в меню', exact: true }).click();
  await page.getByRole('button', { name: 'Сохранить оформление', exact: true }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Изменения сохранены' })).toBeVisible();
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'pyro');
  await expect(page.locator('aside').first()).toContainText('Проверено в браузере');
  await expect(page.locator('aside').first().getByText('Новости и возможности')).toHaveCount(0);
  await page.goto(base + '/brands');
  await expect(page.getByRole('link', { name: 'Настройки', exact: true })).toHaveAttribute('href', '/brands/01J00000000000000000000004/settings');
  await page.getByRole('link', { name: 'Настройки', exact: true }).click();
  await expect(page).toHaveURL(settings);
  await page.getByRole('tab', { name: 'Оформление кабинета', exact: true }).click();
  await expect(
    page.getByRole('textbox', { name: 'Подпись под логотипом', exact: true }),
  ).toHaveValue('Проверено в браузере');
  const actualBrandColor = await page.locator('[data-brand-header]').first().evaluate((element) => getComputedStyle(element).backgroundColor);
  const previewBrandColor = await page.locator('[data-brand-preview-header]').evaluate((element) => getComputedStyle(element).backgroundColor);
  expect(actualBrandColor).toBe(previewBrandColor);
  await expect(
    page.getByRole('switch', { name: 'Показывать промо-карту в меню', exact: true }),
  ).toHaveAttribute('aria-checked', 'false');
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: '.local/screenshots/e1-05/real-theme-saved.png', fullPage: true });
  await page.getByRole('tab', { name: 'Домены', exact: true }).click();
  await page.getByRole('button', { name: 'Добавить домен', exact: true }).click();
  const add = page.getByRole('dialog', { name: 'Добавить домен', exact: true });
  await add.getByRole('textbox', { name: 'Домен', exact: true }).fill('example.com');
  await add.getByRole('button', { name: 'Добавить', exact: true }).click();
  const domain = page.getByRole('article').filter({ hasText: 'example.com' });
  await expect(domain).toBeVisible();
  await domain.getByRole('button', { name: 'Инструкция', exact: true }).click();
  await expect(page.getByRole('dialog', { name: 'Подтверждение домена' })).toContainText(
    '/.well-known/eyon-domain-verification.txt',
  );
  await page.keyboard.press('Escape');
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({
    path: '.local/screenshots/e1-05/real-domain-created.png',
    fullPage: true,
  });
  await domain.getByRole('button', { name: 'Удалить домен example.com', exact: true }).click();
  await page
    .getByRole('dialog', { name: 'Удалить домен', exact: true })
    .getByRole('button', { name: 'Удалить', exact: true })
    .click();
  await expect(domain).toHaveCount(0);
  await page.getByRole('tab', { name: 'Модули', exact: true }).click();
  await page.getByRole('switch', { name: 'Видение: выключен', exact: true }).click();
  await expect(page).toHaveURL(/brands\/01J00000000000000000000004\/modules/);
  await expect(
    page.getByRole('switch', { name: 'Отключить Видение', exact: true }),
  ).toHaveAttribute('aria-checked', 'true');
  await page.goto(settings);
  await page.getByRole('tab', { name: 'Модули', exact: true }).click();
  await expect(page.getByRole('switch', { name: 'Видение: включён', exact: true })).toHaveAttribute(
    'aria-checked',
    'true',
  );
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({
    path: '.local/screenshots/e1-05/real-module-enabled.png',
    fullPage: true,
  });
});
