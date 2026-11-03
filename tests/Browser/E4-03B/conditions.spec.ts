import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

test.setTimeout(60000);
let server: ViteDevServer;
let fixtureUrl: string;
test.beforeAll(async () => {
  server = await createServer({
    configFile: 'tests/Browser/E4-03B/vite.config.ts',
    server: { host: '127.0.0.1', port: 0, strictPort: false },
  });
  await server.listen();
  fixtureUrl = new URL('/tests/Browser/E4-03B/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('сетка скидок и массовая отметка', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(fixtureUrl);
  await expect(page.getByText('Сетка скидок')).toBeVisible();
  await page.getByRole('button', { name: 'Отметить все утверждёнными' }).click();
  await expect(page.getByRole('checkbox')).toHaveCount(4);
  for (const checkbox of await page.getByRole('checkbox').all()) await expect(checkbox).toBeChecked();
  await page.getByRole('button', { name: 'Добавить ступень' }).click();
  await expect(page.getByLabel('От суммы 3')).toBeVisible();
  await page.screenshot({ path: '.local/runtime/e4-03b-conditions.png', fullPage: true });
});
