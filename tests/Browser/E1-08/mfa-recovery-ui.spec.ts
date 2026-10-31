import { expect, test } from '@playwright/test';

const origin = `http://127.0.0.1:${process.env.MFA_VITE_PORT || '5268'}`;
const codes = Array.from({ length: 10 }, (_, i) => `AAAAAAAA-BBBBBBBB-CCCCCCCC-${i.toString(16).padStart(8, '0').toUpperCase()}`);
test.use({ launchOptions: { executablePath: process.env.MFA_BROWSER_EXECUTABLE_PATH || undefined }, trace: 'off' });

test('recovery codes stay out of history and disappear on page hide', async ({ page, context }) => {
  page.on('pageerror', (error) => console.error('fixture page error:', error.message));
  page.on('console', (message) => { if (message.type() === 'error') console.error('fixture console error:', message.text()); });
  await context.addCookies([{ name: 'XSRF-TOKEN', value: 'synthetic-test-cookie', url: origin }]);
  await page.route('**/*', async (route) => {
    const target = new URL(route.request().url());
    if (target.origin !== origin) return route.abort('blockedbyclient');
    if (target.pathname === '/profile/security/mfa/recovery') {
      return route.fulfill({ json: { codes }, headers: { 'Cache-Control': 'no-store, private' } });
    }
    return route.continue();
  });
  await page.goto(`${origin}/tests/Browser/E1-08/ui-fixture.html?screen=security&enabled`);
  await page.getByRole('button', { name: 'Создать резервные коды' }).click();
  await page.getByLabel('Код приложения').fill('123456');
  await page.getByRole('button', { name: 'Создать коды' }).click();
  await expect(page.getByText(codes[0], { exact: true })).toBeVisible();
  expect(await page.evaluate(() => JSON.stringify(history.state))).not.toContain(codes[0]);
  await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true })));
  await expect(page.getByText(codes[0], { exact: true })).toHaveCount(0);
});
