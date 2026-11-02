import { expect, test } from '@playwright/test';

test.use({ launchOptions: process.env.E504_CHROMIUM_PATH ? { executablePath: process.env.E504_CHROMIUM_PATH } : {} });

test('лента и карточка диалога на трёх ширинах', async ({ page }) => {
  const origin = process.env.E504_FIXTURE_ORIGIN ?? 'http://127.0.0.1:5269';
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`${origin}/tests/Browser/E5-04/dialogs-fixture.html?selected`);
    await expect(page.getByRole('heading', { name: 'Диалоги' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Мария Соколова' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Забрать себе' })).toBeVisible();
    await expect(page.getByRole('list', { name: 'Сообщения' }).getByText('Уточните город доставки')).toBeVisible();
    if (width < 1280) {
      await page.getByRole('button', { name: 'Карточка клиента' }).click();
    }
    await expect(page.getByRole('heading', { name: 'Карточка клиента' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    if (width === 1440) await page.screenshot({ path: '.local/screenshots/sp-e5-04/dialog-1440.png', fullPage: true });
  }
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${origin}/tests/Browser/E5-04/dialogs-fixture.html?empty`);
  await expect(page.getByText('Диалогов пока нет.')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e5-04/empty-1440.png', fullPage: true });
});
