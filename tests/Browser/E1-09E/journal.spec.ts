import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let fixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E1-09E/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  fixture = new URL('/tests/Browser/E1-09E/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

test('журнал показывает имя и ссылку объекта', async ({ page }) => {
  await page.goto(fixture);
  await expect(page.getByRole('link', { name: 'Диалог с Иваном' })).toHaveAttribute('href', /seller\/dialogs/);
  await page.getByRole('button', { name: 'Действия с записью' }).first().click();
  await page.getByRole('button', { name: 'Детали действия', exact: true }).click();
  await expect(page.getByText('Диалог с Иваном').last()).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E1-09E/journal.png', fullPage: true });
});
