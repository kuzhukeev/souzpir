import { expect, test } from '@playwright/test';

test.use({
  launchOptions: process.env.PROFILE_CHROMIUM_PATH
    ? { executablePath: process.env.PROFILE_CHROMIUM_PATH }
    : {},
});

test('fragment подтверждения удаляется из адреса до показа профиля', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  const origin = process.env.PROFILE_FIXTURE_ORIGIN ?? 'http://127.0.0.1:5238';
  const token = 'a'.repeat(64);
  await page.goto(origin + '/tests/Browser/E1-08/ui-fixture.html?screen=profile&theme=neutral&pending&confirm#token=' + token);
  await expect(page.getByRole('button', { name: 'Подтвердить адрес' })).toBeEnabled();
  await expect.poll(() => page.evaluate(() => location.hash)).toBe('');
  await expect(page.locator('body')).not.toContainText(token);
  await page.screenshot({ path: '.local/screenshots/sp-e1-08/email-confirm-1440.png' });
});
