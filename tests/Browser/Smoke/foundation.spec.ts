import { expect, test } from '@playwright/test';

test('foundation is rendered by Inertia with no browser errors', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const response = await page.goto('/preview');
  expect(response?.status()).toBe(200);
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Союз Пир');
  await expect(page.getByRole('main')).toBeVisible();
  expect(errors).toEqual([]);
});
