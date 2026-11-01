import { expect, test } from '@playwright/test';

const fixture = 'http://127.0.0.1:5237/tests/Browser/SP-E1-06G/fixture.html';

test('разрешённый сотрудник видит ссылку и может перейти к передаче', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${fixture}?allowed`);
  await page.getByRole('button', { name: 'Продавец', exact: true }).click();
  const nav = page.getByRole('navigation', { name: 'Основная навигация' });
  const link = nav.getByRole('link', { name: 'Передача задач', exact: true });
  await expect(link).toBeVisible();
  await expect(link).toHaveAttribute('href', '/seller/tasks/transfer');
  await link.focus();
  await expect(link).toBeFocused();
  expect(await link.evaluate((element) => getComputedStyle(element).cursor)).toBe('pointer');
  await page.screenshot({ path: '.local/screenshots/sp-e1-06g/navigation-1440.png', fullPage: true });
  let requests = 0;
  await page.route('**/seller/tasks/transfer', (route) => { requests++; void route.abort(); });
  await page.keyboard.press('Enter');
  await expect.poll(() => requests).toBe(1);
});

test('без права пункт скрыт', async ({ page }) => {
  await page.goto(fixture);
  await page.getByRole('button', { name: 'Продавец', exact: true }).click();
  const nav = page.getByRole('navigation', { name: 'Основная навигация' });
  await expect(nav.getByText('Передача задач', { exact: true })).toHaveCount(0);
});
