import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, colorScheme: 'light', launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-22G/vite.config.ts', cacheDir: '.local/runtime/vite-e822g', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E8-22G/fixture.html?page=report', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('кнопка передаёт отчёт, исходный индекс и контекст бренда', async ({ page }) => {
 await page.route('**/vision/actions', async route => { if (route.request().method() === 'POST') await route.fulfill({ status: 422, json: { errors: { recommendation: 'Недоступно' } } }); else await route.continue(); });
 await page.goto(url);
 const button = page.getByRole('button', { name: 'Взять в работу' }); await expect(button).toBeVisible();
 await mkdir('.local/screenshots/SP-E8-22G', { recursive: true }); await page.screenshot({ path: '.local/screenshots/SP-E8-22G/report.png', fullPage: true });
 const posted = page.waitForRequest(req => req.url().endsWith('/vision/actions') && req.method() === 'POST'); await button.click();
 const request = await posted; expect(request.postDataJSON()).toMatchObject({ report_id: 'r', recommendation_index: 7, title: 'Улучшить страницу' });
 expect(request.headers()['x-brand-id']).toBe('b'); expect(request.headers()['x-brand-revision']).toBe('a'.repeat(64));
 await expect(page.getByRole('alert')).toHaveText('Не удалось добавить действие.');
});
test('без права кнопка скрыта, без исходного индекса отключена', async ({ page }) => {
 await page.goto(url+'&viewer'); await expect(page.getByRole('button', { name: 'Взять в работу' })).toHaveCount(0);
 await page.goto(url+'&missing'); await expect(page.getByRole('button', { name: 'Взять в работу' })).toBeDisabled();
});
