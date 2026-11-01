import { expect, test } from '@playwright/test';

const base = 'http://127.0.0.1:5267/tests/Browser/SP-UI-07/fixture.html';
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } });

for (const theme of ['neutral', 'confectionery', 'pyro']) {
  for (const width of [1440, 1024, 390]) {
    test(`module reasons ${theme} ${width}`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${base}?theme=${theme}`);
      const seller = page.getByRole('switch', { name: 'Включить ИИ-продавец' });
      const vision = page.getByRole('switch', { name: 'Отключить Видение' });
      await expect(seller).toBeDisabled();
      await expect(page.getByText('Модуль недоступен по тарифу.')).toBeVisible();
      await expect(seller).toHaveAttribute('aria-describedby', 'module-reason-seller');
      await expect(vision).toBeEnabled();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      if (theme === 'neutral' && width === 1440) {
        await page.screenshot({ path: '.local/screenshots/sp-ui-07/modules-1440.png', fullPage: true });
      }
      await page.goto(`${base}?theme=${theme}&paused`);
      await expect(page.getByRole('switch', { name: 'Отключить Видение' })).toBeDisabled();
      await expect(page.getByRole('switch', { name: 'Включить ИИ-продавец' })).toBeDisabled();
      await expect(page.getByText('Бренд приостановлен — изменение модулей недоступно.')).toHaveCount(2);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    });
  }
}

test('available switch remains usable from keyboard', async ({ page }) => {
  await page.goto(base);
  const vision = page.getByRole('switch', { name: 'Отключить Видение' });
  await vision.focus();
  await page.keyboard.press('Space');
  await expect(page.getByRole('dialog', { name: 'Отключить модуль' })).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog', { name: 'Отключить модуль' })).toHaveCount(0);
});
