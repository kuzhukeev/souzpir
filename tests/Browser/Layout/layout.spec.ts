import { test, expect } from '@playwright/test';
test.use({ launchOptions: process.env.LAYOUT_CHROMIUM_PATH ? { executablePath: process.env.LAYOUT_CHROMIUM_PATH } : {} });
const url = 'http://127.0.0.1:5183/tests/Browser/Layout/fixture.html';
for (const width of [1440, 1024, 390]) {
  test(`responsive layout ${width}`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(url);
    await expect(page.getByRole('heading', { name: 'Диалоги' })).toBeVisible();
    if (width === 390) await page.getByRole('button', { name: 'Открыть меню' }).click();
    const nav = page.getByRole('navigation', { name: 'Основная навигация' }).filter({ visible: true });
    await expect(nav.getByRole('link', { name: 'Диалоги' })).toHaveAttribute('aria-current', 'page');
    await expect(nav.getByText('База знаний')).toBeVisible();
    await expect(nav.getByText('База знаний').locator('..')).toHaveAttribute('aria-disabled', 'true');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    expect(await page.evaluate(() => document.documentElement.scrollHeight <= innerHeight)).toBeTruthy();
    expect(await page.locator('body').evaluate((el) => parseFloat(getComputedStyle(el).fontSize))).toBeGreaterThanOrEqual(13);
    await page.screenshot({ path: `.local/screenshots/layout/layout-${width}.png`, fullPage: true });
  });
}
test('desktop collapse persists; navigation has icons and real routes only', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 }); await page.goto(url);
  await expect(page.getByRole('button', { name: 'Свернуть меню' })).toBeVisible();
  const nav = page.getByRole('navigation', { name: 'Основная навигация' });
  await expect(nav.getByRole('link', { name: 'Диалоги' }).locator('svg')).toHaveCount(1);
  await expect(nav.getByText('База знаний').locator('..')).not.toHaveAttribute('href');
  await page.getByRole('button', { name: 'Свернуть меню' }).click();
  await page.goto(url); await expect(page.getByRole('button', { name: 'Развернуть меню' })).toBeVisible();
});
test('mobile drawer returns focus and brand switch stays inactive', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 }); let posts = 0;
  page.on('request', (request) => { if (request.method() === 'POST' && request.url().includes('brand/switch')) posts++; });
  await page.goto(url); const trigger = page.getByRole('button', { name: 'Открыть меню' }); await trigger.click();
  await expect(page.getByRole('dialog', { name: 'Навигация кабинета' })).toBeVisible();
  await page.keyboard.press('Escape'); await expect(trigger).toBeFocused();
  await expect(page.getByText('Пиротехника · Скоро')).toBeVisible(); expect(posts).toBe(0);
});


test('permissions hide protected items', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 }); await page.goto(url + '?limited=1');
  const nav = page.getByRole('navigation', { name: 'Основная навигация' });
  await expect(nav.getByText('База знаний')).toHaveCount(0);
  await expect(nav.getByRole('link', { name: 'Диалоги' })).toBeVisible();
});
test('enabled brand switch sends selected ID to real endpoint', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  let payload: Record<string, unknown> = {};
  await page.route('**/brand/switch', async (route) => { payload = route.request().postDataJSON(); await route.abort(); });
  await page.goto(url + '?ready=1');
  await page.getByRole('combobox', { name: 'Выбрать бренд' }).click();
  await page.getByRole('option', { name: 'Второй бренд' }).click();
  await expect.poll(() => payload.brand_id).toBe('two');
});
test('axe accessibility checks', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 }); await page.goto(url);
  await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
  const violations = await page.evaluate(async () => (await (window as unknown as { axe: { run: (root: Document, options: object) => Promise<{ violations: { id: string }[] }> } }).axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] } })).violations);
  expect(violations).toEqual([]);
});
