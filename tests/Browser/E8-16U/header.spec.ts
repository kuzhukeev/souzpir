import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-16U/vite.config.ts', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E8-16U/fixture.html?page=report', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('шапка показывает данные выбранного замера', async ({ page }) => {
 await page.goto(url);
 const header = page.locator('header').filter({ has: page.getByRole('heading', {name: 'Отчёт о видимости в нейросетях'}) });
 await expect(header).toContainText('Замер от 27.10.2026');
 await expect(header).toContainText('Вопросов: 20');
 await expect(header).toContainText('Моделей: 2');
 await expect(page.getByRole('button', { name: 'Печать / PDF' })).toBeVisible();
 await expect(page.getByRole('button', { name: 'Поделиться отчётом' })).toBeVisible();
 await mkdir('.local/screenshots/SP-E8-16U', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E8-16U/report.png', fullPage: true });
});
