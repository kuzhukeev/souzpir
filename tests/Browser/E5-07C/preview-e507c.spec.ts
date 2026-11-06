import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E5-07C/vite-e507c.config.ts' }); await server.listen(); url = new URL('/tests/Browser/E5-07C/preview-e507c.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('почта: способ отправки, подпись и безопасный тест', async ({ page }) => {
    await page.goto(url);
    await expect(page.getByLabel('Подпись')).toHaveValue('С уважением');
    await expect(page.getByRole('heading', { name: 'Входящая' })).toBeVisible();
    await expect(page.getByRole('paragraph').filter({ hasText: 'Скоро' })).toBeVisible();
    await expect(page.getByText('без реальной отправки', { exact: false })).toBeVisible();
    await page.getByLabel('SMTP', { exact: true }).check();
    await expect(page.getByLabel('Сервер SMTP')).toBeVisible();
    await page.getByLabel('Сервер SMTP').fill('smtp.example.test');
    await page.getByLabel('Пароль SMTP').fill('test-password');
    await expect(page.getByLabel('Пароль SMTP')).toHaveAttribute('type', 'password');
    await mkdir('.local/screenshots/SP-E5-07C', { recursive: true });
    await page.getByLabel('Пароль SMTP').clear();
    await page.screenshot({ path: '.local/screenshots/SP-E5-07C/email.png', fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByRole('button', { name: 'Отправить тест' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
});

