import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-16L/vite.config.ts', cacheDir: '.local/runtime/vite-e816l', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E8-16L/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('shows current progress and measured estimate', async ({ page }) => { await page.goto(url); await expect(page.getByRole('heading', { name: 'Ход замера' })).toBeVisible(); await expect(page.getByText(/1 из 2 слотов/)).toBeVisible(); await expect(page.getByText(/Осталось примерно/)).toBeVisible(); await mkdir('.local/screenshots/SP-E8-16L', { recursive: true }); await page.screenshot({ path: '.local/screenshots/SP-E8-16L/progress.png', fullPage: true }); });
