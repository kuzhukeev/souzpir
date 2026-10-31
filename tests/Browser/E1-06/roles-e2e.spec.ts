import { expect, test } from '@playwright/test';

test.skip(
  process.env.RUN_TEAM_E2E !== '1',
  'Requires the dedicated Team test server and synthetic fixture',
);
test.use({
  launchOptions: process.env.TEAM_CHROMIUM_PATH
    ? { executablePath: process.env.TEAM_CHROMIUM_PATH }
    : {},
});
const origin = 'http://127.0.0.1:8216';

test.beforeEach(async ({ page }) => {
  await page.route('**/*', (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
});

test('owner signs in and loads the actual protected role matrix', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill('team-e106-owner@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  const matrixResponse = page.waitForResponse(
    (response) => response.url() === `${origin}/team/roles/matrix`,
  );
  const pageResponse = await page.goto(`${origin}/team/roles`);
  expect(pageResponse?.status()).toBe(200);
  expect((await matrixResponse).status()).toBe(200);
  await expect(page.getByRole('heading', { name: 'Матрица ролей' })).toBeVisible();
  await expect(page.getByRole('row', { name: /Менеджер/ })).toContainText('Частично');
  await page.screenshot({ path: '.local/screenshots/e1-06/roles-real-1440.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 900 });
  await expect(page.getByRole('region', { name: 'Роль Менеджер' })).toContainText('Частично');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
  const tooSmall = await page.locator('body').evaluate((body) =>
    Array.from(body.querySelectorAll('*'))
      .filter((node) => {
        const style = getComputedStyle(node);
        const rect = node.getBoundingClientRect();
        return (
          Array.from(node.childNodes).some((child) => child.nodeType === Node.TEXT_NODE && child.textContent?.trim()) &&
          style.visibility !== 'hidden' && style.display !== 'none' && rect.width > 0 && rect.height > 0 &&
          parseFloat(style.fontSize) < 13
        );
      })
      .map((node) => node.outerHTML.slice(0, 160)),
  );
  expect(tooSmall).toEqual([]);
  await page.screenshot({ path: '.local/screenshots/e1-06/roles-real-390.png', fullPage: true });
});

test('viewer cannot open the matrix page or its JSON endpoint', async ({ page }) => {
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill('team-e106-viewer@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  const pageResponse = await page.goto(`${origin}/team/roles`);
  expect(pageResponse?.status()).toBe(403);
  const jsonResponse = await page.request.get(`${origin}/team/roles/matrix`);
  expect(jsonResponse.status()).toBe(403);
});
