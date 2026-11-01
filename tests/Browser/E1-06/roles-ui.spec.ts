import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

const origin = 'http://127.0.0.1:5216';
const fixture = `${origin}/tests/Browser/E1-06/ui-fixture.html`;
const raw = execFileSync(
  process.env.TEAM_TEST_PHP_PATH || 'php',
  [
    '-r',
    "echo json_encode(require 'config/permissions.php', JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);",
  ],
  { encoding: 'utf8' },
);
const config = JSON.parse(raw) as {
  labels: Record<string, string>;
  abilities: Record<string, string[]>;
};
const keys = ['owner', 'admin', 'editor', 'manager', 'viewer'];
const payload = {
  roles: keys.map((key) => ({ key, label: config.labels[key] })),
  abilities: Object.entries(config.abilities).map(([ability, roles]) => ({
    ability,
    roles: Object.fromEntries(keys.map((key) => [key, roles.includes(key)])),
  })),
  limitations: { custom_roles_available: false, per_module_grants_available: false },
};
test.use({
  launchOptions: process.env.TEAM_CHROMIUM_PATH
    ? { executablePath: process.env.TEAM_CHROMIUM_PATH }
    : {},
});
test.beforeEach(async ({ page }) => {
  let failed = false;
  let fixtureParams = new URLSearchParams();
  page.on('framenavigated', (frame) => {
    if (frame === page.mainFrame() && frame.url().startsWith(fixture)) {
      fixtureParams = new URL(frame.url()).searchParams;
    }
  });
  await page.route('**/*', async (route) => {
    const target = new URL(route.request().url());
    if (target.origin !== origin) return route.abort('blockedbyclient');
    if (target.pathname === '/team/roles/matrix') {
      if (fixtureParams.has('error') && !failed) {
        failed = true;
        return route.fulfill({ status: 503, body: 'unavailable' });
      }
      if (fixtureParams.has('empty'))
        return route.fulfill({ status: 200, json: { ...payload, roles: [], abilities: [] } });
      if (fixtureParams.has('invalid')) return route.fulfill({ status: 200, json: { invalid: true } });
      return route.fulfill({ status: 200, json: payload });
    }
    return route.continue();
  });
});

async function readable(page: Page) {
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
}

for (const width of [1440, 1024, 390]) {
  for (const theme of ['neutral', 'pyro', 'confectionery']) {
    test(`${theme} matrix is readable at ${width}`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${fixture}?theme=${theme}`);
      await expect(page.getByRole('heading', { level: 1, name: 'Роли и доступ' })).toBeVisible();
      await expect(page.getByRole('heading', { level: 2, name: 'Матрица ролей' })).toBeVisible();
      if (width < 768)
        await expect(page.getByRole('region', { name: 'Роль Менеджер' })).toContainText('Частично');
      else await expect(page.getByRole('row', { name: /Менеджер/ })).toContainText('Частично');
      await readable(page);
      await page.screenshot({
        path: `.local/screenshots/e1-06/roles-${theme}-${width}.png`,
        fullPage: true,
      });
    });
  }
}

test('keyboard details and accessibility', async ({ page }) => {
  await page.goto(fixture);
  const details = page.getByText('Показать права по действиям');
  await details.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('region', { name: 'Права по действиям' })).toBeVisible();
  await expect(page.getByRole('rowheader', { name: 'Передача владения' })).toBeVisible();
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

test('empty and malformed responses, and retry after a failed request', async ({ page }) => {
  await page.goto(`${fixture}?empty=1`);
  await expect(
    page.getByText('Матрица ролей пока недоступна. Попробуйте обновить страницу позже.'),
  ).toBeVisible();
  await page.goto(`${fixture}?invalid=1`);
  await expect(page.getByRole('alert')).toContainText('Не удалось загрузить');
  await page.goto(`${fixture}?error=1`);
  await expect(page.getByRole('alert')).toContainText('Не удалось загрузить');
  await page.getByRole('button', { name: 'Повторить' }).click();
  await expect(page.getByRole('heading', { name: 'Матрица ролей' })).toBeVisible();
});
