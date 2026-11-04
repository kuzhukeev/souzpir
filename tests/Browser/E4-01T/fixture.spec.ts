import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
import examples from '../../../app/ProtectedOperations/DraftSimulation/fixtures/seeded-context-e401t.json' with { type: 'json' };

let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-01T/vite.config.ts', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E4-01T/run.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });

test('полный синтетический диалог с подтверждённым критерием', async ({ page }) => {
    const example = examples.find(item => item.criterion === 'no_deferred_promise')!;
    await page.goto(url);
    await expect(page.locator('main section')).toHaveCount(example.dialogue.length / 2);
    await expect(page.getByText(example.answer, { exact: false })).toBeVisible();
    await mkdir('.local/screenshots/sp-e4-01t', { recursive: true });
    await page.screenshot({ path: '.local/screenshots/sp-e4-01t/dialogue.png', fullPage: true });
});

test('неподтверждённый исход остаётся без оценки', async ({ page }) => {
    const example = examples.find(item => item.criterion === 'no_pct_above_grid')!;
    await page.goto(url + '?case=unknown');
    await expect(page.locator('main section')).toHaveCount(example.dialogue.length / 2);
    await expect(page.getByText(example.answer, { exact: false })).toBeVisible();
    await expect(page.getByText(/Смысловая проверка недоступна/)).toBeVisible();
    await page.screenshot({ path: '.local/screenshots/sp-e4-01t/unknown.png', fullPage: true });
});
