import { expect, test } from '@playwright/test';

test.use({ launchOptions: process.env.E605_CHROMIUM_PATH ? { executablePath: process.env.E605_CHROMIUM_PATH } : {} });

test('редактор приёмов и пустое состояние', async ({ page }) => {
  const origin = process.env.E605_FIXTURE_ORIGIN ?? 'http://127.0.0.1:5269';
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`${origin}/tests/Browser/E6-05/techniques-fixture.html`);
    await expect(page.getByRole('heading', { name: 'Приёмы продаж' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Опубликовать черновик' })).toBeVisible();
    await expect(page.getByLabel('Название')).toHaveValue('Подорожание с конкретным расчётом');
    await expect(page.getByText('31%')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    if (width === 1440) await page.screenshot({ path: '.local/screenshots/sp-e6-05/techniques-1440.png', fullPage: true });
  }
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${origin}/tests/Browser/E6-05/techniques-fixture.html?empty`);
  await expect(page.getByText('Приёмов пока нет.')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e6-05/empty-1440.png', fullPage: true });
});
