import { expect, test } from '@playwright/test';

test.use({
  launchOptions: process.env.E110_CHROMIUM_PATH
    ? { executablePath: process.env.E110_CHROMIUM_PATH }
    : {},
});

test('создание вебхука показывает события и одноразовый секрет', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  const origin = process.env.E110_FIXTURE_ORIGIN ?? 'http://127.0.0.1:5237';
  await page.goto(origin + '/tests/Browser/E1-10/ui-fixture.html?screen=api&theme=neutral');
  await page.getByRole('button', { name: 'Добавить вебхук' }).click();
  await expect(page.getByRole('dialog', { name: 'Добавить вебхук' })).toBeVisible();
  await expect(page.getByLabel('HTTPS адрес')).toBeEnabled();
  await expect(page.getByText('Новый лид', { exact: true }).last()).toBeVisible();
  await expect(page.getByLabel('Секрет нового вебхука')).toHaveText(/^[a-f0-9]{64}$/);
  await page.screenshot({ path: '.local/screenshots/sp-e1-10f/webhook-create-1440.png' });
});
