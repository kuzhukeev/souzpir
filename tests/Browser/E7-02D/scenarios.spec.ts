import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let base: string;
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } });
test.beforeAll(async () => {
    server = await createServer({ configFile: 'tests/Browser/E7-02D/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
    await server.listen();
    base = new URL('/tests/Browser/E7-02D/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

test('run all and new criteria at 1440', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(base);
    await expect(page.getByRole('button', { name: 'Запустить все' })).toBeEnabled();
    await mkdir('.local/screenshots/sp-e7-02d', { recursive: true });
    await page.screenshot({ path: '.local/screenshots/sp-e7-02d/list-1440.png', fullPage: true });
    await page.getByRole('button', { name: 'Изменить' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByLabel('Не нарушены запреты')).toBeChecked();
    await expect(dialog.getByLabel('Оценка ≥ 4')).toBeChecked();
    await dialog.getByLabel('Не нарушены запреты').scrollIntoViewIfNeeded();
    await page.screenshot({ path: '.local/screenshots/sp-e7-02d/editor-1440.png', fullPage: true });
});

test('empty state disables run all', async ({ page }) => {
    await page.goto(base + '?empty');
    await expect(page.getByRole('button', { name: 'Запустить все' })).toBeDisabled();
});