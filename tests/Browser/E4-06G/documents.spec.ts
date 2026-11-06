import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-06G/vite.config.ts' }); await server.listen(); url = new URL('/tests/Browser/E4-06G/fixture.html?imports', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('сохранённый исходник доступен, старый отсутствующий обозначен', async ({ page }) => {
 await page.goto(url);
 await expect(page.getByRole('link', { name: 'Скачать исходник' })).toHaveAttribute('href', '/seller/knowledge/imports/documents/saved/download');
 await expect(page.getByRole('row').filter({ hasText: 'Старый.txt' })).toContainText('Исходник недоступен');
 await mkdir('.local/screenshots/SP-E4-06G', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E4-06G/documents.png', fullPage: true });
});
