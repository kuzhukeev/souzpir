import { expect, test } from '@playwright/test';

test.use({
  launchOptions: process.env.E804_CHROMIUM_PATH
    ? { executablePath: process.env.E804_CHROMIUM_PATH }
    : {},
});

test('запуск замера виден на странице истории', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  const origin = process.env.E804_FIXTURE_ORIGIN ?? 'http://127.0.0.1:5237';
  await page.goto(origin + '/tests/Browser/E8-04/launch-fixture.html');
  await expect(page.getByRole('heading', { name: 'Замеры', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Начать замер' })).toBeEnabled();
  await expect(page.getByText('20 окт. 2026 г.').first()).toBeVisible();
  await expect(page.getByText('Сохранённый ответ бренда')).toBeVisible();
  await expect(page.getByText('Ошибка: Провайдер отклонил запрос')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e8-04b/progress-1440.png' });
  await page.goto(origin + '/tests/Browser/E8-04/launch-fixture.html?pending');
  await expect(page.getByRole('button', { name: 'Выполнить следующий слот' })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e8-04b/pending-1440.png' });
});
