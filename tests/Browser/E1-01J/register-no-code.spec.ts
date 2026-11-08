import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.setTimeout(60000);
test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E1-01J/vite.config.ts', cacheDir: '.local/runtime/vite-e101j', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E1-01J/fixture.html', server.resolvedUrls!.local[0]).href; await mkdir('.local/screenshots/SP-E1-01J', { recursive: true }); });
test.afterAll(async () => { await server?.close(); });

for (const width of [1440, 390]) {
  test(`регистрация без кода не показывает поле кода при ширине ${width}`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(url + '?needsCode=0');
    await expect(page.getByRole('heading', { name: 'Создать аккаунт' })).toBeVisible();
    await expect(page.getByLabel('Имя', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Рабочая почта')).toBeVisible();
    await expect(page.getByLabel('Код создания кабинета')).toHaveCount(0);
    await expect(page.getByText(/Код нужен только для создания/)).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    if (width === 1440) await page.screenshot({ path: '.local/screenshots/SP-E1-01J/register-no-code.png', fullPage: true });
  });
}

test('регистрация с кодом по-прежнему показывает поле кода', async ({ page }) => {
  await page.goto(url + '?needsCode=1');
  await expect(page.getByLabel('Код создания кабинета')).toBeVisible();
});
