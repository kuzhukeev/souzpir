import { expect, test } from '@playwright/test';

const origin = 'http://127.0.0.1:5216';
const fixture = `${origin}/tests/Browser/E1-06/team-list-fixture.html`;
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
    test(`${theme}: команда при ширине ${width}`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${fixture}?theme=${theme}`);
      await expect(page.getByRole('heading', { level: 1, name: 'Команда' })).toBeVisible();
      const listing =
        width < 768
          ? page.locator('article')
          : page.getByRole('region', { name: 'Таблица участников' });
      await expect(listing.getByText('Иван Петров', { exact: true })).toBeVisible();
      await expect(listing.getByText('Нет общих доступных брендов')).toBeVisible();
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
        path: `.local/screenshots/e1-06/team-list-${theme}-${width}.png`,
        fullPage: true,
      });
    });
  }
}

test('клавиатура и доступность', async ({ page }) => {
  await page.goto(fixture);
  const next = page.getByRole('button', { name: 'Вперёд' });
  await next.focus();
  await expect(next).toBeFocused();
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
