import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
let full: string;
let empty: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(() => {
    mkdirSync('.local/tmp', { recursive: true });
    const render = (mode: string) => execFileSync(process.env.PHP_BINARY || 'php', ['-d', `sys_temp_dir=${resolve('.local/tmp')}`, 'tests/Browser/E8-19K/render.php', mode], { encoding: 'utf8', env: { ...process.env, APP_ENV: 'local', APP_INSTANCE: 'e819k-fixture' } });
    full = render('full'); empty = render('empty');
});
test('сравнение и динамика доступны только для сопоставимых данных', async ({ page }) => {
    for (const width of [1440, 1024, 390]) {
        await page.setViewportSize({ width, height: 900 });
        for (const colorScheme of ['light', 'dark'] as const) {
            await page.emulateMedia({ colorScheme }); await page.setContent(full);
            await expect(page.getByText('К предыдущему замеру: +20,0 п.п.')).toBeVisible();
            await expect(page.getByText('К предыдущему замеру: +2', { exact: true })).toBeVisible();
            await expect(page.getByRole('img', { name: 'Динамика: Ошибок о вас' })).toBeVisible();
            await expect(page.getByText('PRIVATE_ANSWER_DO_NOT_PUBLISH')).toHaveCount(0);
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        }
        if (width === 1440) { await page.emulateMedia({ colorScheme: 'light' }); await page.setContent(full); mkdirSync('.local/screenshots/SP-E8-19K', { recursive: true }); await page.screenshot({ path: '.local/screenshots/SP-E8-19K/report.png', fullPage: true }); }
    }
    await page.setContent(empty);
    await expect(page.getByText('Нет сопоставимых данных для сравнения.')).toHaveCount(4);
    await expect(page.getByRole('img', { name: 'Динамика: Ошибок о вас' })).toHaveCount(0);
});
