import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-18J/vite.config.ts', cacheDir: '.local/runtime/vite-e818j', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E8-18J/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('report waits for incomplete questionnaire', async ({ page }) => {
 const revision = 'a'.repeat(64);
 await page.route('**/vision/report/data**', route => route.fulfill({ json: { revision, questionnaire_complete: false, confirmed: [], reports: [], report: null } }));
 await page.route('**/vision/runs/revision', route => route.fulfill({ json: { revision } }));
 await page.goto(url);
 await expect(page.getByText('Откроется после завершения анкеты.')).toBeVisible();
 await mkdir('.local/screenshots/SP-E8-18J', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E8-18J/report.png', fullPage: true });
});
