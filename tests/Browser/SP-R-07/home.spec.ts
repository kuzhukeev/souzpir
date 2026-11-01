import { expect, test } from '@playwright/test';

const base = 'http://127.0.0.1:5270/tests/Browser/SP-R-07/fixture.html';
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } });

for (const theme of ['neutral', 'confectionery', 'pyro']) {
  for (const width of [1440, 1024, 390]) {
    test(`home links ${theme} ${width}`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${base}?theme=${theme}`);
      const links = page.getByRole('region', { name: 'Разделы кабинета' }).getByRole('link');
      await expect(links).toHaveCount(4);
      await expect(links.nth(0)).toHaveAttribute('href', '/company');
      await expect(links.nth(3)).toHaveAttribute('href', '/profile');
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      await links.first().focus();
      await page.keyboard.press('Tab');
      await page.keyboard.press('Shift+Tab');
      await expect(links.first()).toHaveCSS('outline-style', 'solid');
      if (theme === 'neutral' && width === 1440) {
        await page.screenshot({ path: '.local/screenshots/sp-r-07/home-1440.png', fullPage: true });
      }
    });
  }
}

test('empty destinations show the same empty state', async ({ page }) => {
  await page.goto(`${base}?empty`);
  await expect(page.getByText('Доступных разделов пока нет.')).toBeVisible();
});
