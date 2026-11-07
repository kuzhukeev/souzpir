import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.use({
  launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
    ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH }
    : {},
});
test.beforeAll(async () => {
  server = await createServer({
    configFile: 'tests/Browser/E1-10H/vite.config.ts',
    server: { host: '127.0.0.1', port: 0, watch: null },
  });
  await server.listen();
  url = new URL('/tests/Browser/E1-10H/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => {
  await server?.close();
});
test('ссылки и статус почты на экране подключений', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(url);
  const tile = page
    .locator('article')
    .filter({ has: page.getByRole('heading', { name: 'Почта для прайса' }) });
  await expect(tile.getByText('Настроена')).toBeVisible();
  await expect(tile.getByRole('link', { name: 'Настроить' })).toHaveAttribute(
    'href',
    '/seller/channels/email',
  );
  const crmTile = page
    .locator('article')
    .filter({ has: page.getByRole('heading', { name: 'CRM-вебхук' }) });
  await expect(crmTile.getByText('Подключён 20.10.2026')).toBeVisible();
  await expect(crmTile.getByRole('link', { name: 'Настроить' })).toHaveAttribute(
    'href',
    '/seller/integrations/crm',
  );
  await mkdir('.local/screenshots/SP-E1-10H', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E1-10H/integrations.png', fullPage: true });
  for (const width of [1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await expect(tile).toBeVisible();
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBe(true);
  }
});
