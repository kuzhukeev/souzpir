import { test, expect } from '@playwright/test';

test.use({ launchOptions: process.env.ONBOARDING_CHROMIUM_PATH ? { executablePath: process.env.ONBOARDING_CHROMIUM_PATH } : {} });

test('real authenticated owner saves draft, resumes and creates the first brand', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('http://127.0.0.1:8102/login');
  await page.getByLabel('Рабочая почта').fill('onboarding-browser@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  await page.goto('http://127.0.0.1:8102/start');
  await page.getByLabel('Название компании').fill('ООО Союз Пир');
  await page.getByLabel('Регион').fill('Москва');
  await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
  await expect(page.getByRole('heading', { name: 'Сайт и бренд' })).toBeVisible();
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Сайт и бренд' })).toBeVisible();
  await page.getByLabel('Название бренда').fill('Союз Пир');
  await page.getByLabel('Логотип', { exact: true }).setInputFiles('.local/tmp/e1-logo.png');
  await expect(page.getByRole('status').filter({ hasText: 'Логотип сохранён' })).toBeVisible();
  await expect(page.getByAltText('Сохранённый логотип бренда')).toBeVisible();
  const originalLogoUrl = await page.getByAltText('Сохранённый логотип бренда').getAttribute('src');
  await page.getByLabel('Логотип', { exact: true }).setInputFiles('.local/tmp/e1-logo.png');
  await expect.poll(() => page.getByAltText('Сохранённый логотип бренда').getAttribute('src')).not.toBe(originalLogoUrl);
  await page.getByLabel('Адрес сайта').fill('https://example.test');
  await page.getByRole('radio', { name: /Пиротехника/ }).check();
  await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
  await expect(page.getByRole('heading', { name: 'Выберите модули' })).toBeVisible();
  await page.getByRole('checkbox', { name: /ИИ-продавец/ }).check();
  await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
  await expect(page.getByRole('heading', { name: 'Первый шаг', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Завершить настройку' }).click();
  await expect(page.getByRole('heading', { name: 'Настройка завершена' })).toBeVisible();
  await page.reload();
  await expect(page.getByText('Данные компании и бренда сохранены.')).toBeVisible();
});
