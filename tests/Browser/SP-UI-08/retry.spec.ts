import { expect, test } from '@playwright/test';

const fixture = 'http://127.0.0.1:5237/tests/Browser/SP-UI-08/fixture.html';

test('повтор показывает состояние строки, ошибку и успех', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(fixture);
  await expect(page.getByRole('button', { name: 'Повторить', exact: true })).toHaveCount(2);
  let release = () => {};
  const wait = new Promise<void>((resolve) => { release = resolve; });
  await page.route('**/journal/notifications/*/retry', async (route) => {
    await wait;
    await route.fulfill({ status: 500, body: 'test error' });
  });
  const first = page.getByRole('button', { name: 'Повторить', exact: true }).first();
  await first.focus();
  await page.keyboard.press('Enter');
  const pending = page.getByRole('button', { name: 'Повторяем…' });
  await expect(pending).toBeVisible();
  await expect(pending).toHaveAttribute('aria-busy', 'true');
  await expect(page.getByRole('button', { name: 'Повторить', exact: true })).toHaveCount(1);
  await page.screenshot({ path: '.local/screenshots/sp-ui-08/retry-1440.png', fullPage: true });
  release();
  await expect(page.getByRole('status')).toContainText('Не удалось повторить доставку.');
  await expect(page.getByRole('button', { name: 'Повторить', exact: true })).toHaveCount(2);
  await page.unroute('**/journal/notifications/*/retry');
  const inertiaPage = await page.evaluate(() => JSON.parse(document.querySelector<HTMLScriptElement>('script[data-page="app"]')?.textContent ?? '{}'));
  await page.route('**/journal/notifications/*/retry', (route) => route.fulfill({
    status: 200,
    headers: { 'X-Inertia': 'true', 'Content-Type': 'application/json' },
    body: JSON.stringify(inertiaPage),
  }));
  await page.setViewportSize({ width: 390, height: 844 });
  const last = page.getByRole('button', { name: 'Повторить', exact: true }).last();
  await last.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toContainText('Уведомление поставлено в очередь повторной доставки.');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
});
