import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-10R/vite.config.ts', cacheDir: '.local/runtime/vite-e410r', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E4-10R/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('диалог и проверка ответа работают на опубликованной версии знаний', async ({ page }) => {
    await page.route('**/seller/knowledge/sandbox/reply', async route => {
        const body = route.request().postDataJSON();
        expect(body.release).toBe('published');
        expect(body.simulation).toBe(true);
        await route.fulfill({ json: { status: 'completed', release: body.release, client_id: null, channel_id: null, history: [{ question: body.text, answer: 'Ответ на опубликованной версии' }], state: { stage: 'qualify', slots: {} }, inspector: { intent: 'ask_min_order', client: null, channel: null, previous_answer: null, goal: { kind: 'answer_only', slot: null, facts: [], trigger: null, allowed_tools: [] }, executed_tools: [], guard: { verdict: 'ok', issues: [], warnings: [] } } } });
    });
    await page.route('**/seller/knowledge/sandbox/check', async route => {
        const body = route.request().postDataJSON();
        expect(body.release).toBe('published');
        await route.fulfill({ json: { verdict: 'ok', issues: [], warnings: [] } });
    });
    await page.goto(url);
    await expect(page.getByLabel('Версия знаний')).toHaveValue('published');
    await expect(page.getByText('Тестовые диалоги не попадают в статистику.')).toBeVisible();
    await page.getByLabel('Сообщение клиента').fill('Какой минимальный заказ?');
    await page.getByRole('button', { name: 'Получить ответ' }).click();
    await expect(page.getByLabel('История диалога')).toContainText('Ответ на опубликованной версии');
    await expect(page.getByText('Нет подходящей записи')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Сохранить как контрольный сценарий' })).toBeVisible();
    await page.getByLabel('Текст ответа').fill('Минимальный заказ — 30 000 ₽.');
    await page.getByRole('button', { name: 'Проверить' }).click();
    await expect(page.getByText('Проверка пройдена', { exact: true })).toBeVisible();
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'neutral'));
    await mkdir('.local/screenshots/SP-E4-10R', { recursive: true });
    await page.screenshot({ path: '.local/screenshots/SP-E4-10R/sandbox-published.png', fullPage: true });
});
