import { expect, test } from '@playwright/test';

const origin = process.env.UI12_FIXTURE_ORIGIN ?? 'http://127.0.0.1:5237';
const fixture = `${origin}/tests/Browser/SP-UI-12/fixture.html`;
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } });

test('прямая ссылка, обновление и неизвестная вкладка', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${fixture}?theme=neutral&tab=theme`);
  const theme = page.getByRole('tab', { name: 'Оформление кабинета' });
  const general = page.getByRole('tab', { name: 'Основное' });
  await expect(theme).toHaveAttribute('data-state', 'active');
  await page.reload();
  await expect(theme).toHaveAttribute('data-state', 'active');
  await expect(page.getByRole('radio')).toHaveCount(3);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.goto(`${fixture}?theme=neutral&tab=unknown`);
  await expect(general).toHaveAttribute('data-state', 'active');
  await expect(page.getByLabel('Название бренда')).toBeVisible();
});

test('клавиатура и Back/Forward сохраняют выбранную вкладку', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${fixture}?theme=neutral`);
  const general = page.getByRole('tab', { name: 'Основное' });
  const theme = page.getByRole('tab', { name: 'Оформление кабинета' });
  await general.focus();
  await page.keyboard.press('ArrowRight');
  await expect(theme).toHaveAttribute('data-state', 'active');
  await expect(page).toHaveURL(/tab=theme/);
  await expect(page).toHaveURL(/theme=neutral/);
  await page.screenshot({ path: '.local/screenshots/sp-ui-12/theme-1440.png', fullPage: true });
  await page.goBack();
  await expect(general).toHaveAttribute('data-state', 'active');
  await page.goForward();
  await expect(theme).toHaveAttribute('data-state', 'active');
  await general.click();
  await expect(page).not.toHaveURL(/tab=/);
});
