import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
    server = await createServer({ configFile: 'tests/Browser/E5-10D/vite.config.ts', cacheDir: '.local/runtime/vite-e510d', server: { host: '127.0.0.1', port: 0 } });
    await server.listen();
    url = new URL('/tests/Browser/E5-10D/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('пометки видны во входящих и применяются только вручную', async ({ page }) => {
    let postCount = 0;
    await page.route('**/seller/knowledge/incoming', route => { postCount++; return route.abort('failed'); });
    await page.goto(url);
    const incoming = page.getByRole('heading', { name: /Входящие/ }).locator('..');
    for (const name of ['Непонятно', 'Канцелярит', 'Слишком длинно', 'Неверный факт', 'Неверный тон', 'Другое']) {
        await expect(incoming.getByText(`Пометка: ${name}`)).toBeVisible();
    }
    await expect(incoming.getByText('Ответ 0', { exact: false })).toBeVisible();
    await expect(incoming.getByText('Комментарий 0', { exact: false })).toBeVisible();
    expect(postCount).toBe(0);
    await mkdir('.local/screenshots/SP-E5-10D', { recursive: true });
    await incoming.screenshot({ path: '.local/screenshots/SP-E5-10D/incoming.png' });
    const first = incoming.locator('li').filter({ hasText: 'Пометка: Непонятно' });
    await first.getByLabel('Группа фактов').fill('delivery');
    const sent = page.waitForRequest(request => request.method() === 'POST' && request.url().endsWith('/seller/knowledge/incoming'));
    await first.getByRole('button', { name: 'Применить', exact: true }).click();
    const body = (await sent).postDataJSON();
    expect(body.id).toBe('review-0');
    expect(body.decision).toBe('accept');
    expect(body.text).toBe('Уточнённый ответ');
    expect(postCount).toBe(1);
    await expect(first.getByRole('alert')).toContainText('Нет связи');
});

test('входящие доступны на основных ширинах и в трёх темах', async ({ page }) => {
    for (const width of [1440, 1024, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(url);
        for (const theme of ['neutral', 'confectionery', 'pyro']) {
            await page.evaluate(value => document.documentElement.setAttribute('data-theme', value), theme);
            await expect(page.getByRole('heading', { name: /Входящие/ })).toBeVisible();
            await expect(page.getByText('Пометка: Непонятно')).toBeVisible();
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        }
        await page.keyboard.press('Tab');
        await expect(page.locator(':focus')).toBeVisible();
    }
});
