import { expect, test } from '@playwright/test';

const base = 'http://127.0.0.1:5237/tests/Browser/SP-UI-06/fixture.html';
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } });

for (const theme of ['neutral', 'confectionery', 'pyro']) {
  for (const width of [1440, 1024, 390]) {
    test(`settings navigation ${theme} ${width}`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${base}?theme=${theme}`);
      const nav = page.getByRole('navigation', { name: 'Настройки продавца' });
      const link = nav.getByRole('link', { name: 'Уведомления' });
      await expect(link).toBeVisible();
      await expect(link).toHaveAttribute('href', '/seller/settings/notifications');
      expect(await link.evaluate((element) => getComputedStyle(element).cursor)).toBe('pointer');
      await link.focus();
      await page.keyboard.press('Tab');
      await page.keyboard.press('Shift+Tab');
      await expect(link).toHaveCSS('outline-style', 'solid');
      await expect(nav.getByRole('link', { name: /Скоро/ })).toHaveCount(0);
      for (const name of ['Режим работы', 'Распределение']) {
        const settingsLink = nav.getByRole('link', { name });
        await expect(settingsLink).toBeVisible();
        await expect(settingsLink).toHaveAttribute('href', '/seller/settings');
      }
      await expect(nav.locator('[aria-disabled="true"]')).toHaveCount(0);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      if (theme === 'neutral' && width === 1440) {
        await page.screenshot({ path: '.local/screenshots/sp-ui-06/limits-1440.png', fullPage: true });
      }
    });
  }
}

test('missing notifications route remains unavailable', async ({ page }) => {
  await page.goto(`${base}?unavailable`);
  const nav = page.getByRole('navigation', { name: 'Настройки продавца' });
  await expect(nav.getByRole('link', { name: 'Уведомления' })).toHaveCount(0);
  await expect(nav.getByText('Уведомления · Скоро')).toHaveAttribute('aria-disabled', 'true');
});
