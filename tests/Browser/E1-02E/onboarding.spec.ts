import { test, expect } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { createServer, type ViteDevServer } from 'vite';

test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
let server: ViteDevServer;
let fixture: string;
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E1-02E/vite.config.ts' }); await server.listen(); fixture = new URL('/tests/Browser/E1-02E/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });

test('проверка сайта и выбор SVG в мастере', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(fixture);
  await expect(page.getByRole('heading', { name: 'Данные бренда' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Проверить сайт' })).toBeVisible();
  await expect(page.locator('input[type=file]')).toHaveAttribute('accept', /image\/svg\+xml/);
  await expect(page.getByText('Сайт доступен')).toBeVisible();
  await mkdir('.local/screenshots/SP-E1-02E', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E1-02E/brand.png', fullPage: true });
  await page.getByLabel('Адрес сайта').fill('https://changed.example.com');
  await expect(page.getByText('Сайт доступен')).toHaveCount(0);
  let uploads = 0;
  page.on('request', request => { if (request.method() === 'POST' && request.url().includes('/start/logo')) uploads++; });
  await page.getByLabel('Логотип', { exact: true }).setInputFiles({ name: 'invalid.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg/>') });
  await expect(page.getByRole('alert')).toContainText('SVG пока не поддерживается в таком формате');
  expect(uploads).toBe(0);
});
