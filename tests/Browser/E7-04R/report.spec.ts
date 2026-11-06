import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, colorScheme: 'light', launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E7-04R/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E7-04R/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('сводка показывает сохранённые показатели', async ({ page }) => {
 await page.goto(url);
 await page.getByRole('button', { name: 'Отчёты' }).click();
 const summary = page.locator('dl[aria-label="Показатели сводки"]');
 await expect(summary).toContainText('С контактом');
 await expect(summary).toContainText('Передано менеджеру');
 await expect(summary).toContainText('Завершено');
 await expect(summary.locator('dd')).toHaveText(['1', '0', '0']);
 await mkdir('.local/screenshots/SP-E7-04R', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E7-04R/report.png', fullPage: true });
});


test('PDF uses report type and current filters', async ({page}) => {
 await page.goto(url);
 await page.getByRole('button',{name:'Отчёты'}).click();
 await page.getByLabel('Тип отчёта').selectOption('topics');
 const download = page.getByRole('link',{name:'Скачать PDF'});
 const target = new URL(await download.getAttribute('href') ?? '', url);
 expect(target.pathname).toBe('/seller/analytics/report.pdf');
 expect(target.searchParams.get('report_type')).toBe('topics');
 expect(target.searchParams.get('from')).toBe('2026-11-01');
 expect(target.searchParams.get('to')).toBe('2026-11-03');
});
