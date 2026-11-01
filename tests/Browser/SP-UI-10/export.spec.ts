import { expect, test } from '@playwright/test';

const fixture = `${process.env.UI10_FIXTURE_ORIGIN ?? 'http://127.0.0.1:5237'}/tests/Browser/SP-UI-10/fixture.html`;
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } });

test('обратный диапазон исправляется до запроса с клавиатуры', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(fixture);
  const from = page.getByLabel('С даты');
  const to = page.getByLabel('По дату');
  const submit = page.getByRole('button', { name: 'Подготовить архив' });
  const inertiaPage = JSON.parse((await page.locator('script[data-page]').textContent()) ?? '{}');
  let requests = 0;
  await page.route('**/settings/data/exports', (route) => {
    requests++;
    return route.fulfill({
      status: 200,
      headers: { 'Content-Type': 'application/json', 'X-Inertia': 'true' },
      body: JSON.stringify(inertiaPage),
    });
  });
  await from.fill('2026-11-20');
  await to.fill('2026-11-01');
  await submit.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByText('Дата «С» не может быть позже даты «По».').first()).toBeVisible();
  await expect(from).toHaveValue('2026-11-20');
  await expect(to).toHaveValue('2026-11-01');
  expect(requests).toBe(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.screenshot({ path: '.local/screenshots/sp-ui-10/export-invalid-1440.png', fullPage: true });
  await to.fill('2026-11-30');
  await expect(page.getByText('Дата «С» не может быть позже даты «По».')).toHaveCount(0);
  await submit.click();
  await expect.poll(() => requests).toBe(1);
  await expect(page.getByRole('status')).toContainText('Архив подготовлен.');
  await expect(from).toHaveValue('2026-11-20');
  await expect(to).toHaveValue('2026-11-30');
});
