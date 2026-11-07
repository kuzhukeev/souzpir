import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, colorScheme: 'light', launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E6-04I/vite.config.ts', cacheDir: '.local/runtime/vite-e604i', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E6-04I/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('источники и переходы к настройкам', async ({ page }) => {
 await page.goto(url);
 await expect(page.getByText('Файл прайса загружен в каталог.')).toBeVisible();
 await expect(page.getByText('Источник остатков: загруженный каталог.')).toBeVisible();
 await expect(page.getByRole('link', { name: 'Настроить файл прайса →' })).toHaveAttribute('href', '/seller/integrations/catalog');
 await expect(page.getByRole('link', { name: 'Настроить источник остатков →' })).toHaveAttribute('href', '/seller/integrations/stock');
 await mkdir('.local/screenshots/SP-E6-04I', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E6-04I/tools.png', fullPage: true });
 await page.goto(url+'?empty');
 await expect(page.getByText('Файл прайса не загружен или недоступен.')).toBeVisible();
 await expect(page.getByText('Каталог не загружен; данные об остатках недоступны.')).toBeVisible();
});
