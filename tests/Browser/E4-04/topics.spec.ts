import { expect, test } from '@playwright/test';

test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });

test('список тем и редактор черновика', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('http://127.0.0.1:5275/tests/Browser/E4-04/fixture.html');
  await expect(page.getByRole('heading', { name: 'Темы вопросов' })).toBeVisible();
  await expect(page.getByText('ask_delivery')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e4-04/topics-1440.png', fullPage: true });
  await page.getByRole('button', { name: 'Редактировать' }).first().click();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByLabel('Описание для бота *')).toHaveValue('Когда клиент спрашивает о доставке и сроках получения заказа.');
  await expect(dialog.getByLabel('Текст вопроса-мостика')).toHaveValue('В какой город нужна доставка?');
  await page.screenshot({ path: '.local/screenshots/sp-e4-04/topic-editor-1440.png', fullPage: true });
  await dialog.getByRole('button', { name: 'Отмена' }).click();
  await expect(dialog).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
