import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-06J/vite.config.ts' }); await server.listen(); url = new URL('/tests/Browser/E4-06J/fixture.html?imports', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('фрагменты открыты сразу после просмотра и связаны с фактами', async ({ page }) => {
 await page.route('**/seller/knowledge/imports/documents/saved/preview?*', route => route.fulfill({ json: { proposals: ['Первый фрагмент'], document_id: 'saved', sheets: [], document: { id: 'saved', name: 'Ранее.pdf', type: 'pdf', pages: 3, status: 'imported', fragments_count: 1, has_source: true, created_at: '2026-11-01' } } }));
 await page.goto(url);
 await page.getByRole('button', { name: 'Посмотреть', exact: true }).click();
 const fragment = page.getByRole('button', { name: 'Первый фрагмент' });
 await expect(fragment).toBeVisible();
 await fragment.click();
 await expect(fragment).toHaveAttribute('aria-pressed', 'true');
 await mkdir('.local/screenshots/SP-E4-06J', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E4-06J/fragments.png', fullPage: true });
});
