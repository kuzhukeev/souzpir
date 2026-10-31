import { expect, test } from '@playwright/test';
const url = process.env.ORGANIZATIONS_FIXTURE_URL || 'http://127.0.0.1:5183/tests/Browser/E1-04/ui-fixture.html';
test.use({ launchOptions: process.env.ORGANIZATIONS_CHROMIUM_PATH ? { executablePath: process.env.ORGANIZATIONS_CHROMIUM_PATH } : {} });
for (const width of [1440, 1024, 390]) {
  for (const screen of ['company', 'brands', 'modules']) {
    test(`${screen} fits ${width}`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 }); await page.goto(`${url}?screen=${screen}`);
      await expect(page.getByRole('heading', { name: screen === 'modules' ? 'Модули бренда' : 'Компания и бренды' })).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
      const small = await page.locator('body').evaluate((body) => Array.from(body.querySelectorAll('*')).filter((node) => { const style = getComputedStyle(node); const rect = node.getBoundingClientRect(); return Array.from(node.childNodes).some((child) => child.nodeType === Node.TEXT_NODE && child.textContent?.trim()) && style.visibility !== 'hidden' && style.display !== 'none' && rect.width > 0 && rect.height > 0 && parseFloat(style.fontSize) < 13; }).map((node) => node.outerHTML.slice(0, 120)));
      expect(small).toEqual([]);
      await page.screenshot({ path: `.local/screenshots/e1-04/${screen}-${width}.png`, fullPage: true });
    });
  }
}
for (const screen of ['company', 'brands', 'modules']) {
  test(`${screen} passes axe WCAG`, async ({ page }) => {
    await page.goto(`${url}?screen=${screen}`); await expect(page.getByRole('heading').first()).toBeVisible();
    await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
    const violations = await page.evaluate(async () => (await (window as unknown as { axe: { run: (root: Document, options: object) => Promise<{ violations: { id: string }[] }> } }).axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] } })).violations);
    expect(violations).toEqual([]);
  });
}
test('owner transfer dialog can be opened and closed by keyboard', async ({ page }) => {
  await page.goto(url); await page.getByRole('button', { name: 'Передать владение' }).click();
  await expect(page.getByRole('dialog', { name: 'Передать владение' })).toBeVisible();
  await page.getByRole('combobox', { name: 'Новый владелец' }).click();
  await page.getByRole('option', { name: /Анна Смирнова/ }).click();
  await page.keyboard.press('Escape'); await expect(page.getByRole('button', { name: 'Передать владение' })).toBeFocused();
});
test('brand empty, archive and module disable confirmations are honest', async ({ page }) => {
  await page.goto(`${url}?screen=brands&empty=1`); await expect(page.getByText('Пока нет брендов')).toBeVisible();
  await page.goto(`${url}?screen=brands`); await page.getByRole('button', { name: 'Архивные' }).click(); await expect(page.getByText('Северные огни')).toBeVisible();
  await page.goto(`${url}?screen=modules`); await page.getByRole('switch', { name: 'Отключить ИИ-продавец' }).click();
  await expect(page.getByRole('dialog', { name: 'Отключить модуль' })).toContainText('Сохранённые данные останутся');
});
