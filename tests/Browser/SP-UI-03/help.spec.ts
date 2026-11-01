import { expect, test } from '@playwright/test';

const base = 'http://127.0.0.1:5237/tests/Browser/SP-UI-03/fixture.html';
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } });

for (const theme of ['neutral', 'confectionery', 'pyro']) {
  for (const width of [1440, 1024, 390]) {
    test(`search and links ${theme} ${width}`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${base}?theme=${theme}`);
      const search = page.getByRole('textbox', { name: 'Поиск' });
      const section = page.getByRole('link', { name: /Начало работы/ });
      const article = page.getByRole('link', { name: /Первое руководство/ });
      await expect(section).toBeVisible();
      await expect(article).toBeVisible();
      expect(await section.evaluate((el) => getComputedStyle(el).cursor)).toBe('pointer');
      expect(await article.evaluate((el) => getComputedStyle(el).cursor)).toBe('pointer');
      await search.fill('начало');
      await expect(section).toBeVisible();
      await expect(article).toHaveCount(0);
      await page.getByRole('button', { name: 'Сбросить поиск' }).click();
      await expect(article).toBeVisible();
      await search.fill('совсем нет');
      await expect(page.getByRole('heading', { name: 'Ничего не найдено' })).toBeVisible();
      await expect(section).toHaveCount(0);
      await page.getByRole('button', { name: 'Сбросить поиск' }).click();
      await expect(section).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      if (theme === 'neutral' && width === 1440) {
        await page.screenshot({ path: '.local/screenshots/sp-ui-03/help-1440.png', fullPage: true });
      }
      await search.focus();
      await page.keyboard.press('Shift+Tab');
      await expect(page.locator(':focus-visible')).toHaveCount(1);
      await section.focus();
      await expect(section).toHaveCSS('outline-style', 'solid');
      const popup = page.waitForEvent('popup');
      await page.keyboard.press('Enter');
      await expect(await popup).toHaveURL(/#guide$/);
    });
  }
}
