import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.use({viewport: {width: 1440, height: 900}, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}});
test.beforeAll(async () => { server = await createServer({configFile: 'tests/Browser/E5-09B/vite.config.ts', server: {host: '127.0.0.1', port: 0, strictPort: false}}); await server.listen(); url = new URL('/tests/Browser/E5-09B/fixture-05c.html?attention', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('показывает неизвестное время отключения', async ({page}) => { await page.goto(url); await expect(page.getByRole('table').getByText('1 · Время отключения неизвестно')).toBeVisible(); await mkdir('.local/screenshots/SP-E5-09B', {recursive: true}); await page.screenshot({path: '.local/screenshots/SP-E5-09B/attention.png', fullPage: true}); });
