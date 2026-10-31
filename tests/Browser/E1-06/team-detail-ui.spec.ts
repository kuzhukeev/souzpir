import { expect, test } from '@playwright/test';

const origin = 'http://127.0.0.1:5216';
const fixture = `${origin}/tests/Browser/E1-06/team-detail-fixture.html`;
test.use({
  launchOptions: process.env.TEAM_CHROMIUM_PATH
    ? { executablePath: process.env.TEAM_CHROMIUM_PATH }
    : {},
});
test.beforeEach(async ({ page }) => {
  await page.route('**/*', (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
});
for (const width of [1440, 1024, 390]) {
  for (const theme of ['neutral', 'pyro', 'confectionery']) {
    test(`${theme}: карточка при ширине ${width}`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${fixture}?theme=${theme}`);
      await expect(page.getByRole('heading', { level: 1, name: 'Анна Иванова' })).toBeVisible();
      await expect(page.getByText('+7 999 123-45-67')).toBeVisible();
      await expect(page.getByRole('region', { name: 'Роль и доступ' })).toContainText(
        'Пиротехника — Северные огни',
      );
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
        path: `.local/screenshots/e1-06/team-detail-${theme}-${width}.png`,
        fullPage: true,
      });
    });
  }
}
test('клавиатура и доступность', async ({ page }) => {
  await page.goto(fixture);
  const back = page.getByRole('link', { name: 'К участникам' });
  await back.focus();
  await expect(back).toBeFocused();
  await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
  const violations = await page.evaluate(
    async () =>
      (
        await (
          window as unknown as {
            axe: {
              run: (root: Document, options: object) => Promise<{ violations: { id: string }[] }>;
            };
          }
        ).axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] } })
      ).violations,
  );
  expect(violations).toEqual([]);
});
