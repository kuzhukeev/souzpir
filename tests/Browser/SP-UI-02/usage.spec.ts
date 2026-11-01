import { test, expect } from '@playwright/test';

const fixture = 'http://127.0.0.1:5237/tests/Browser/SP-UI-02/fixture.html';

test('период проверяется до запроса и исправляется с клавиатуры', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(fixture);
  let requests = 0;
  page.on('request', (request) => { if (request.method() === 'GET' && new URL(request.url()).pathname === '/billing/usage') requests++; });
  const from = page.getByLabel('С', { exact: true });
  const to = page.getByLabel('По', { exact: true });
  await from.fill('2026-11-20');
  await to.fill('2026-11-01');
  await to.focus();
  for (let i = 0; i < 6 && !(await page.getByRole('button', { name: 'Показать' }).evaluate((button) => button === document.activeElement)); i++) await page.keyboard.press('Tab');
  await expect(page.getByRole('button', { name: 'Показать' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.getByText('Дата начала не может быть позже даты окончания.')).toBeVisible();
  await expect(from).toHaveAttribute('aria-invalid', 'true');
  await expect(from).toHaveValue('2026-11-20');
  await expect(to).toHaveValue('2026-11-01');
  expect(requests).toBe(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.screenshot({ path: '.local/screenshots/sp-ui-02/usage-invalid-1440.png', fullPage: true });
  await to.fill('2026-11-30');
  await expect(page.getByText('Дата начала не может быть позже даты окончания.')).toHaveCount(0);
  await page.getByRole('button', { name: 'Показать' }).click();
  await expect.poll(() => requests).toBe(1);
  await expect(from).toHaveValue('2026-11-20');
  await expect(to).toHaveValue('2026-11-30');
});

test('ошибка сервера остаётся видимой', async ({ page }) => {
  await page.goto(fixture);
  await page.route('**/billing/usage?*', (route) => route.fulfill({ status: 500, body: 'test failure' }));
  await page.getByRole('button', { name: 'Показать' }).click();
  await expect(page.getByRole('alert')).toContainText('Не удалось связаться с сервером.');
});

