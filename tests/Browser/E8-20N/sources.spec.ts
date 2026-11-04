import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let vite: ViteDevServer;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.E820_CHROMIUM_PATH ? { executablePath: process.env.E820_CHROMIUM_PATH } : {} });
test.beforeAll(async () => { vite = await createServer({ configFile: 'tests/Browser/E8-20N/vite.config.ts' }); await vite.listen(); });
test.afterAll(async () => { await vite?.close(); });

test('цитирования и сохранённый статус площадки', async ({ page }) => {
    await page.goto('http://127.0.0.1:5411/tests/Browser/E8-20N/fixture.html');
    await expect(page.getByRole('columnheader', { name: 'Цитирований' })).toBeVisible();
    await expect(page.getByRole('row', { name: /example.test/ }).getByRole('cell', { name: '5' })).toBeVisible();
    await expect(page.getByText('сложность: Нет данных')).toBeVisible();
    await expect(page.getByText('В работе')).toBeVisible();
    await mkdir('.local/screenshots/sp-e8-20n', { recursive: true });
    await page.screenshot({ path: '.local/screenshots/sp-e8-20n/sources.png', fullPage: true });
    await page.getByRole('button', { name: 'example.test' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Цитирований: 5')).toBeVisible();
    await expect(dialog.getByText('Действие по площадке:')).toBeVisible();
    await expect(dialog.getByRole('button', { name: /Добавить в действия/ })).toHaveCount(0);
    await page.screenshot({ path: '.local/screenshots/sp-e8-20n/source-drawer.png', fullPage: true });
});
