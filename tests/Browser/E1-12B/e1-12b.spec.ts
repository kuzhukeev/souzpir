import { expect, test } from '@playwright/test';

test.skip(process.env.RUN_E112B_FIXTURE !== '1', 'Requires the isolated E1-12B fixture server.');

const base = 'http://127.0.0.1:5285/tests/Browser/E1-12B/fixture.html';
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.E112B_CHROME ? { executablePath: process.env.E112B_CHROME } : {} });

test('ссылки базы знаний ведут к условиям и образцам', async ({ page }) => {
  await page.goto(base + '?screen=releases');
  await expect(page.getByRole('link', { name: 'Условия', exact: true })).toHaveAttribute('href', '/seller/knowledge/conditions');
  await expect(page.getByRole('link', { name: 'Образцы речи', exact: true }).first()).toHaveAttribute('href', '/seller/knowledge/fewshot');
  await expect(page.getByText('Редактирование условий и образцов будет подключено отдельно.')).toHaveCount(0);
  await page.screenshot({ path: '.local/screenshots/e1-12b/releases.png', fullPage: true });
});
test('на лимитах доступны настройки', async ({ page }) => {
  await page.goto(base + '?screen=limits');
  for (const [name, href] of [['Режим работы', '/seller/settings'], ['Распределение', '/seller/settings'], ['Уведомления', '/seller/settings/notifications']]) {
    await expect(page.getByRole('link', { name, exact: true })).toHaveAttribute('href', href);
  }
  await page.screenshot({ path: '.local/screenshots/e1-12b/limits.png', fullPage: true });
});
test('статус объясняет источник и отсутствие истории', async ({ page }) => {
  await page.goto(base + '?screen=status');
  await expect(page.getByText('Демонстрационные данные')).toBeVisible();
  await expect(page.getByText(/Кабинет и база данных проверяются напрямую/)).toBeVisible();
  await expect(page.getByText('Инциденты за 30 дней')).toBeVisible();
  await expect(page.getByText('История инцидентов пока недоступна')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/e1-12b/status.png', fullPage: true });
});

test('обновление статуса показывает загрузку и ERR_LOAD', async ({ page }) => {
  await page.goto(base + '?screen=status');
  await page.route('**/fixture.html?screen=status', async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 300));
    await route.fulfill({ status: 500, body: 'Ошибка' });
  });
  await page.getByRole('button', { name: /Обновить/ }).click();
  await expect(page.locator('div[aria-busy="true"]')).toBeVisible();
  await expect(page.getByText('ERR_LOAD')).toBeVisible();
});

 test('отсутствующий маршрут уведомлений отмечен недоступным', async ({ page }) => {
  await page.goto(base + '?screen=limits&unavailable');
  await expect(page.getByRole('link', { name: 'Уведомления', exact: true })).toHaveCount(0);
  await expect(page.getByText('Уведомления · Скоро')).toHaveAttribute('aria-disabled', 'true');
});
