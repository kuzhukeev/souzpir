import { expect, test } from '@playwright/test';

test.use({ launchOptions: process.env.E605_CHROMIUM_PATH ? { executablePath: process.env.E605_CHROMIUM_PATH } : {} });

test('пункт приёмов доступен с клавиатуры и скрыт без права', async ({ page }) => {
  const origin = process.env.E605_FIXTURE_ORIGIN ?? 'http://127.0.0.1:5269';
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${origin}/tests/Browser/E6-05/navigation-fixture.html`);
  const link = page.getByRole('link', { name: 'Приёмы продаж' });
  await expect(link).toHaveAttribute('href', '/seller/scenario/techniques');
  await expect(link).toHaveAttribute('aria-current', 'page');
  await link.focus();
  await expect(link).toBeFocused();
  await page.keyboard.press('Tab');
  await page.keyboard.press('Shift+Tab');
  await expect(link).toBeFocused();
  await page.route('**/seller/scenario/techniques', route => route.fulfill({ status: 204 }));
  await Promise.all([page.waitForRequest('**/seller/scenario/techniques'), page.keyboard.press('Enter')]);
  await page.goto(`${origin}/tests/Browser/E6-05/navigation-fixture.html?viewer`);
  await expect(page.getByRole('link', { name: 'Приёмы продаж' })).toHaveCount(0);
});

