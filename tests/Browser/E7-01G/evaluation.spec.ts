import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E7-01G/vite.config.ts' }); await server.listen(); url = new URL('/tests/Browser/E7-01G/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('карточка показывает оценки, проблемы и переход к диалогу', async ({ page }) => {
  await page.goto(url);
  await expect(page.getByRole('heading', { name: 'Оценка диалога' })).toBeVisible();
  await expect(page.getByText('Уточните наличие', { exact: false })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Открыть диалог →' })).toHaveAttribute('href', '/seller/dialogs/01ARZ3NDEKTSV4RRFFQ69G5FAV');
  await mkdir('.local/screenshots/SP-E7-01G', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E7-01G/evaluation.png', fullPage: true });
});
