import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
    server = await createServer({ configFile: 'tests/Browser/E5-10D/vite.config.ts', cacheDir: '.local/runtime/vite-e510d-reviews', server: { host: '127.0.0.1', port: 0 } });
    await server.listen();
    url = new URL('/tests/Browser/E5-10D/reviews-fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

test('скелетон, затем массовая отправка выбранных пометок во входящие', async ({ page }) => {
    await page.goto(url);
    const skeleton = page.getByRole('status', { name: 'Загрузка пометок' });
    await expect(skeleton).toBeVisible();
    await expect(skeleton.getByRole('heading', { name: 'Загрузка' })).toBeVisible();
    await mkdir('.local/screenshots/SP-E5-10D', { recursive: true });
    await page.screenshot({ path: '.local/screenshots/SP-E5-10D/reviews-loading.png' });
    await page.evaluate(() => window.dispatchEvent(new Event('fixture-ready')));
    await expect(skeleton).toHaveCount(0);
    const send = page.getByRole('button', { name: 'Отправить во Входящие базы знаний' });
    await expect(send).toBeDisabled();
    await expect(page.getByText('Выбрано: 0')).toBeVisible();
    const all = page.getByRole('checkbox', { name: 'Выбрать все новые пометки' });
    await all.focus();
    await page.keyboard.press('Space');
    await expect(page.getByText('Выбрано: 2')).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: 'Звучит резко' }).getByRole('checkbox')).toHaveCount(0);
    await expect(page.getByRole('row').filter({ hasText: 'Непонятно.' })).toContainText('Образцы речи');
    await page.screenshot({ path: '.local/screenshots/SP-E5-10D/reviews.png', fullPage: true });
    let body = '';
    await page.route('**/seller/reviews/send', async (route) => { body = route.request().postData() ?? ''; await route.abort(); });
    await send.click();
    await expect(page.getByRole('alert')).toContainText('Нет связи');
    expect(JSON.parse(body).ids).toHaveLength(2);
    await expect(page.getByRole('button', { name: 'Отклонить выбранные' })).toBeEnabled();
    const width = await page.evaluate(() => document.documentElement.scrollWidth);
    expect(width).toBeLessThanOrEqual(1440);
});

test('наблюдатель не видит массовых действий; узкий экран и темы', async ({ page }) => {
    await page.goto(`${url}?viewer`);
    await page.evaluate(() => window.dispatchEvent(new Event('fixture-ready')));
    await expect(page.getByText('Доставка доступна каждый день.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Отправить во Входящие базы знаний' })).toHaveCount(0);
    await expect(page.getByRole('checkbox')).toHaveCount(0);
    await page.goto(url);
    await page.evaluate(() => window.dispatchEvent(new Event('fixture-ready')));
    for (const theme of ['neutral', 'confectionery', 'pyro']) {
        await page.evaluate(value => document.documentElement.setAttribute('data-theme', value), theme);
        await expect(page.getByRole('heading', { name: 'Типы пометок за период' })).toBeVisible();
    }
    for (const width of [1024, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(page.getByRole('button', { name: 'Отправить во Входящие базы знаний' })).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    }
});
