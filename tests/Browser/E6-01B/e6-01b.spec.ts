import { expect, test } from '@playwright/test';
test.skip(process.env.RUN_E601B_FIXTURE !== '1', 'Requires isolated E6-01B fixture server.');
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.E601B_CHROME ? { executablePath: process.env.E601B_CHROME } : {} });
const fixture = 'http://127.0.0.1:5287/tests/Browser/E6-01B/fixture.html';
test('профиль и пример', async ({ page }) => {
  await page.goto(fixture);
  await page.getByLabel('Должность').fill('Менеджер');
  await page.getByRole('button', { name: 'Обновить пример' }).click();
  await expect(page.locator('p').getByText('Здравствуйте, я Антон.')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/e6-01b/persona.png', fullPage: true });
});
test('семь фраз и быстрые ответы отправляются вместе', async ({ page }) => {
  await page.goto(fixture);
  await page.getByRole('button', { name: 'Служебные фразы' }).click();
  await expect(page.locator('textarea[maxlength="500"]')).toHaveCount(7);
  await page.getByLabel('При ошибке').fill('Повторите позже');
  await page.getByLabel('Быстрые ответы менеджера (по одному на строку)').fill('Добрый день');
  await page.screenshot({ path: '.local/screenshots/e6-01b/phrases.png', fullPage: true });
  await page.route('**/seller/persona', route => route.fulfill({ status: 422, contentType: 'application/json', body: '{}' }));
  const request = page.waitForRequest('**/seller/persona');
  await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
  const body = (await request).postDataJSON();
  expect(body.service_phrases.error).toBe('Повторите позже');
  expect(body.quick_replies).toEqual(['Добрый день']);
});
