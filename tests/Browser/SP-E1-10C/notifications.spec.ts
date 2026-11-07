import { expect, test } from '@playwright/test';

test.use({
  launchOptions: process.env.E110_CHROMIUM_PATH
    ? { executablePath: process.env.E110_CHROMIUM_PATH }
    : {},
});

test('настройки уведомлений видимы в светлой теме', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  const origin = process.env.E110_FIXTURE_ORIGIN ?? 'http://127.0.0.1:5237';
  await page.goto(origin + '/tests/Browser/E1-10/ui-fixture.html?screen=notifications&theme=neutral');
  await expect(page.getByRole('heading', { name: 'Уведомления компании' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Подключить Telegram' })).toBeEnabled();
  await expect(page.getByLabel('Адреса электронной почты')).toBeEnabled();
  await expect(page.getByRole('button', { name: 'Сохранить' })).toBeEnabled();
  await page.screenshot({ path: '.local/screenshots/sp-e1-10c/notifications-1440.png', fullPage: true });
});
