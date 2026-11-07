import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-10/topics-hint-vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E4-10/topics-hint-fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('подсказка пустого списка тем не предлагает создать черновик, если он уже есть', async ({ page }) => {
  await page.goto(url);
  await expect(page.getByText('Тем пока нет. Добавьте первую тему.')).toBeVisible();
  await expect(page.getByText('Создайте черновик и добавьте первую тему.')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Добавить тему' })).toBeVisible();
  await mkdir('.local/screenshots/SP-E4-10', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E4-10/topics-empty-draft.png', fullPage: true });
});
