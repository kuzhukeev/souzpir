import { expect, test } from '@playwright/test';

const base = 'http://127.0.0.1:5240/tests/Browser/E8-10/fixture.html';
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH }, viewport: { width: 1440, height: 900 } });

test('four wizard screens and typed controls', async ({ page }) => {
  await page.goto(`${base}?step=mentions`);
  await expect(page.getByRole('heading', { name: 'Это про вас?' })).toBeVisible();
  await page.getByRole('radio', { name: 'Да, это мы' }).check();
  await expect(page.getByText('Отметить для добавления в профиль')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e8-10/mentions-1440.png', fullPage: true });
  await page.goto(`${base}?step=presence`);
  await expect(page.getByRole('heading', { name: 'Вы есть на этих площадках?' })).toBeVisible();
  await expect(page.getByRole('textbox', { name: 'Ссылка на профиль (если есть)' })).toBeVisible();
  await page.goto(`${base}?step=facts`);
  await page.getByRole('radio', { name: 'Неверно' }).check();
  await expect(page.getByRole('textbox', { name: 'Как правильно' })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e8-10/facts-1440.png', fullPage: true });
  await page.goto(`${base}?step=done`);
  await expect(page.getByText('Перерасчёт показателей ещё не подключён', { exact: false })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Открыть отчёт' })).toHaveAttribute('href', '/vision/report');
  await page.screenshot({ path: '.local/screenshots/sp-e8-10/done-1440.png', fullPage: true });
});

test('empty and unavailable states', async ({ page }) => {
  await page.goto(`${base}?step=presence&empty`);
  await expect(page.getByText('Нет данных для уточнения на этом шаге.')).toBeVisible();
  await page.goto(`${base}?step=facts&unavailable`);
  await expect(page.getByText('Уточнения станут доступны после подтверждения завершённого замера.')).toBeVisible();
});
