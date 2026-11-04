import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let fixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E8-24B/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  fixture = new URL('/tests/Browser/E8-24B/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

test('расписание показывает три следующих замера', async ({ page }) => {
  await page.goto(fixture);
  await page.getByRole('button', { name: 'Расписание' }).click();
  await expect(page.getByRole('region', { name: 'Следующие три замера' }).locator('li')).toHaveCount(3);
  await expect(page.getByText('2026-11-09 09:00 · Europe/Moscow')).toBeVisible();
  await page.getByRole('button', { name: 'Подробнее о слотах' }).click();
  await expect(page.getByText(/Объём проверок зависит от числа вопросов/)).toBeVisible();
  await expect(page.getByText(/Расход и остаток слотов не предоставлены источником/)).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E8-24C/slots.png', fullPage: true });
});
