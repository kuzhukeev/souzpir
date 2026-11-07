import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E1-08F/vite.config.ts' }); await server.listen(); url = new URL('/tests/Browser/E1-08F/fixture.html?screen=security&enabled=1', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('экран безопасности показывает управление резервными кодами', async ({ page }) => {
  await page.goto(url);
  await expect(page.getByText(/резервн.*код/i).first()).toBeVisible();
  await mkdir('.local/screenshots/SP-E1-08F', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E1-08F/security.png', fullPage: true });
});
