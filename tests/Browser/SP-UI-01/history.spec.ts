import { expect, test } from '@playwright/test';

const base = 'http://127.0.0.1:5237/tests/Browser/SP-UI-01/fixture.html';
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } });

for (const theme of ['neutral', 'confectionery', 'pyro']) {
  for (const width of [1440, 1024, 390]) {
    test(`empty history ${theme} ${width}`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${base}?theme=${theme}`);
      await expect(page.getByText('Выполненных действий пока нет.')).toBeVisible();
      await expect(page.getByText('Текущее действие')).toHaveCount(0);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      await page.keyboard.press('Tab');
      await expect(page.locator(':focus-visible')).toHaveCount(1);
      if (theme === 'neutral' && width === 1440) {
        await page.screenshot({ path: '.local/screenshots/sp-ui-01/empty-history-1440.png', fullPage: true });
      }
    });
  }
}

test('completed history stays on history screen, current action stays on actions screen', async ({ page }) => {
  await page.goto(`${base}?filled`);
  await expect(page.getByText('Завершённое действие')).toBeVisible();
  await expect(page.getByText('Текущее действие')).toHaveCount(0);
  await page.goto(`${base}?section=actions`);
  await expect(page.getByText('Текущее действие')).toBeVisible();
  await expect(page.getByText('Завершённое действие')).toHaveCount(0);
});
