import { expect, test } from '@playwright/test';

test.skip(process.env.RUN_E110_STATUS_BROWSER !== '1', 'Requires isolated status servers and test database.');
test.use({
  trace: 'off', screenshot: 'off', video: 'off',
  launchOptions: { executablePath: process.env.E110_CHROMIUM_PATH },
});
const available = 'http://127.0.0.1:18451';
const unavailable = 'http://127.0.0.1:18452';
const assets = 'http://127.0.0.1:5249';

test('настоящая страница различает настроенный и недоступный маршрут API', async ({ page }) => {
  await page.route('**/*', (route) => [available, unavailable, assets].includes(new URL(route.request().url()).origin) ? route.continue() : route.abort('blockedbyclient'));
  await page.goto(`${available}/login`);
  await page.getByLabel('Рабочая почта').fill('owner-e110@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  await page.goto(`${available}/integrations/api`);
  await expect(page.getByText(/Маршрут чтения текущего бренда настроен/)).toBeVisible();
  await expect(page.getByText('Запросы API для работы с диалогами и лидами пока недоступны.')).toBeVisible();
  await expect(page.getByText(/Настройка и отправка вебхуков временно недоступны/)).toBeVisible();
  expect(await page.evaluate(async () => (await fetch('/api/v1/brand', { credentials: 'omit' })).status)).toBe(401);

  await page.goto(`${unavailable}/integrations/api`);
  await expect(page.getByText('Чтение бренда через API сейчас не подтверждено или недоступно.')).toBeVisible();
  await expect(page.getByText(/Маршрут чтения текущего бренда настроен/)).toHaveCount(0);
  await expect(page.getByText('Запросы API для работы с диалогами и лидами пока недоступны.')).toBeVisible();
  await expect(page.getByText(/Настройка и отправка вебхуков временно недоступны/)).toBeVisible();
  expect(await page.evaluate(async () => (await fetch('/api/v1/brand', { credentials: 'omit' })).status)).toBe(503);
});
