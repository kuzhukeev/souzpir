import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
    server = await createServer({ configFile: 'tests/Browser/E4-02D/vite.config.ts', cacheDir: '.local/runtime/vite-e402d', server: { host: '127.0.0.1', port: 0 } });
    await server.listen();
    url = new URL('/tests/Browser/E4-02D/fixture.html?card', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('правила и выбор интента отправляются с карточкой и сохраняются при ошибке', async ({ page }) => {
    await page.goto(url);
    await page.getByLabel('Чего нельзя говорить рядом').fill('Не обещать сроки');
    await page.getByLabel('Мостик к продаже').fill('Уточнить город');
    await page.getByLabel('Вопрос об оплате').check();
    await page.getByLabel('Добавить условие').selectOption('min_order_rub');
    await expect(page.getByRole('button', { name: 'Обновить пример' })).toBeDisabled();
    await mkdir('.local/screenshots/SP-E4-02D', { recursive: true });
    await page.screenshot({ path: '.local/screenshots/SP-E4-02D/card.png', fullPage: true });
    const sent = page.waitForRequest(request => request.method() === 'POST' && request.url().includes('/seller/knowledge/facts/'));
    await page.route('**/seller/knowledge/facts/**', route => route.abort('failed'));
    await page.getByRole('button', { name: 'Сохранить факт' }).click();
    const body = (await sent).postDataJSON();
    expect(body.must_not).toBe('Не обещать сроки');
    expect(body.bridge_hint).toBe('Уточнить город');
    expect(body.intent_ids).toEqual(['ask_payment']);
    expect(body.text).toContain('{cond:min_order_rub}');
    await expect(page.getByRole('alert')).toContainText('Нет связи');
    await expect(page.getByLabel('Чего нельзя говорить рядом')).toHaveValue('Не обещать сроки');
});

test('карточка доступна на основных ширинах и с клавиатуры', async ({ page }) => {
    for (const width of [1440, 1024, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(url);
        for (const theme of ['neutral', 'confectionery', 'pyro']) {
            await page.evaluate(value => document.documentElement.setAttribute('data-theme', value), theme);
            await expect(page.getByRole('heading', { name: 'Карточка факта' })).toBeVisible();
            await expect(page.getByLabel('Факт', { exact: true })).toBeVisible();
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        }
        await page.keyboard.press('Tab');
        await expect(page.locator(':focus')).toBeVisible();
    }
});
