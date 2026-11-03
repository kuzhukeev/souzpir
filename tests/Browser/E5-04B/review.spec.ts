import { test, expect } from '@playwright/test';
test('форма пометки доступна на широком и мобильном экране', async ({ page }) => {
  for (const width of [1440, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/tests/Browser/E5-04B/fixture.html');
    await page.getByRole('button', { name: 'Пометить' }).click();
    await expect(page.getByLabel('Причина')).toBeVisible();
    await expect(page.getByRole('option', { name: 'Неверный факт' })).toHaveCount(1);
    if (width === 1440) await page.screenshot({ path: '.local/runtime/e5-04c-review.png', fullPage: true });
  }
});
