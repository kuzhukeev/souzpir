import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let fixtureUrl: string;

test.beforeAll(async () => {
  server = await createServer({
    configFile: 'tests/Browser/E5-04B/vite.config.ts',
    server: { host: '127.0.0.1', port: 0, strictPort: false },
  });
  await server.listen();
  fixtureUrl = new URL('/tests/Browser/E5-04B/fixture.html', server.resolvedUrls!.local[0]).href;
});

test.afterAll(async () => { await server?.close(); });

test('форма пометки доступна на широком и мобильном экране', async ({ page }) => {
  for (const width of [1440, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(fixtureUrl);
    await page.getByRole('button', { name: 'Пометить' }).click();
    await expect(page.getByLabel('Причина')).toBeVisible();
    await expect(page.getByRole('option', { name: 'Неверный факт' })).toHaveCount(1);
    if (width === 1440) await page.screenshot({ path: '.local/runtime/e5-04c-review.png', fullPage: true });
  }
});
