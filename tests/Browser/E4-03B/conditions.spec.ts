import { test, expect } from '@playwright/test';
test('сетка скидок и массовая отметка', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/tests/Browser/E4-03B/fixture.html');
  await expect(page.getByText('Сетка скидок')).toBeVisible();
  await page.getByRole('button', { name: 'Отметить все утверждёнными' }).click();
  await expect(page.getByRole('checkbox')).toHaveCount(4);
  for (const checkbox of await page.getByRole('checkbox').all()) await expect(checkbox).toBeChecked();
  await page.getByRole('button', { name: 'Добавить ступень' }).click();
  await expect(page.getByLabel('От суммы 3')).toBeVisible();
  await page.screenshot({ path: '.local/runtime/e4-03b-conditions.png', fullPage: true });
});
