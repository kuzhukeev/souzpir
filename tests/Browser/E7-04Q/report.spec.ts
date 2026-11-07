import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, colorScheme: 'light', launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E7-04Q/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E7-04Q/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('сводка показывает сохранённые показатели', async ({ page }) => {
 await page.goto(url);
 await page.getByRole('button', { name: 'Отчёты' }).click();
 const summary = page.locator('dl[aria-label="Показатели сводки"]');
 await expect(summary).toContainText('С контактом');
 await expect(summary).toContainText('Передано менеджеру');
 await expect(summary).toContainText('Завершено');
 await expect(summary.locator('dd')).toHaveText(['1', '0', '0']);
 await mkdir('.local/screenshots/SP-E7-04Q', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E7-04Q/report.png', fullPage: true });
});
