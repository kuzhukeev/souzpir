import { expect, test } from '@playwright/test';

test.use({
  launchOptions: process.env.TEAM_CHROMIUM_PATH
    ? { executablePath: process.env.TEAM_CHROMIUM_PATH }
    : {},
});
const origin = 'http://127.0.0.1:5232';
const first = '01J00000000000000000000001';
const second = '01J00000000000000000000002';
const revision = 'a'.repeat(64);
test.beforeEach(async ({ page }) => {
  await page.route('**/*', (route) => {
    const url = new URL(route.request().url());
    if (url.origin !== origin) return route.abort('blockedbyclient');
    if (url.pathname === '/team/member-last-logins')
      return route.fulfill({
        json: {
          revision,
          available: true,
          members: [
            { id: first, lastLoginAt: '2026-11-01T09:34:00.000000Z' },
            { id: second, lastLoginAt: null },
          ],
        },
      });
    return route.continue();
  });
});

for (const width of [1440, 1024, 390]) {
  for (const theme of ['neutral', 'pyro', 'confectionery']) {
    test(`${theme}, ${width}: дата и отсутствие истории`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${origin}/tests/Browser/E1-06D/last-login-fixture.html?theme=${theme}`);
      await expect(page.getByText(/12:34/)).toBeVisible();
      await expect(page.getByText('Нет данных')).toBeVisible();
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
      ).toBeTruthy();
      const small = await page.locator('body').evaluate((body) =>
        Array.from(body.querySelectorAll('*'))
          .filter((node) => {
            const style = getComputedStyle(node);
            const rect = node.getBoundingClientRect();
            return (
              Array.from(node.childNodes).some(
                (child) => child.nodeType === Node.TEXT_NODE && child.textContent?.trim(),
              ) &&
              style.visibility !== 'hidden' &&
              style.display !== 'none' &&
              rect.width > 0 &&
              rect.height > 0 &&
              parseFloat(style.fontSize) < 13
            );
          })
          .map((node) => node.outerHTML.slice(0, 160)),
      );
      expect(small).toEqual([]);
      await page.screenshot({
        path: `.local/screenshots/e1-06d/last-login-${theme}-${width}.png`,
        fullPage: true,
      });
    });
  }
}

test('клавиатура и доступность компонента', async ({ page }) => {
  await page.goto(`${origin}/tests/Browser/E1-06D/last-login-fixture.html`);
  await expect(page.getByText('Нет данных')).toBeVisible();
  const retry = page.getByRole('button', { name: 'Повторить' });
  await retry.focus();
  await expect(retry).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.getByText(/12:34/)).toBeVisible();
  await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
  const violations = await page.evaluate(async () =>
    (
      await (
        window as unknown as {
          axe: {
            run: (root: Document, options: object) => Promise<{ violations: { id: string }[] }>;
          };
        }
      ).axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] } })
    ).violations.map((item) => item.id),
  );
  expect(violations).toEqual([]);
});
