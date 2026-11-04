import { expect, test } from '@playwright/test';
test.skip(process.env.RUN_E703C_FIXTURE !== '1', 'Requires isolated E7-03C fixture server.');
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.E703C_CHROME ? { executablePath: process.env.E703C_CHROME } : {} });
const fixture = 'http://127.0.0.1:5286/tests/Browser/E7-03C/fixture.html';
test('сравнение показывает итог и изменения', async ({ page }) => {
  await page.goto(fixture + '?compare');
  await expect(page.getByText(/Всего: 1/)).toBeVisible();
  await expect(page.getByText('Что изменилось: оценка выросла')).toBeVisible();
  await expect(page.locator('select[multiple]')).toHaveCount(2);
  await expect(page.getByRole('link', { name: 'Калибровка оценщика' })).toHaveAttribute('href', '/seller/quality/calibration');
  await page.screenshot({ path: '.local/screenshots/e7-03c/compare.png', fullPage: true });
});
test('оценки меняют график и отправляются одним запросом', async ({ page }) => {
  await page.goto(fixture);
  await expect(page.getByText('Показаны последние 100 прогонов.', { exact: false })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Открыть диалог' })).toHaveAttribute('href', '/seller/dialogs/01J00000000000000000000001');
  await page.getByLabel('Ваша оценка').first().selectOption('5');
  await expect(page.getByText('+1.5', { exact: true })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/e7-03c/calibration.png', fullPage: true });
  await page.route('**/seller/quality/calibration', route => route.fulfill({ status: 422, contentType: 'application/json', body: '{}' }));
  const request = page.waitForRequest('**/seller/quality/calibration');
  await page.getByRole('button', { name: 'Сохранить оценки' }).click();
  expect((await request).postDataJSON().items).toHaveLength(2);
});
