import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
test.setTimeout(60000);
let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-10Q/vite.config.ts', cacheDir: '.local/runtime/vite-e410q', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E4-10Q/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('песочница показывает историю и контекст, переключает версию и очищает диалог', async ({ page }) => {
    let history: { question: string; answer: string }[] = [];
    await page.route('**/seller/knowledge/sandbox/reply', async route => {
        const body = route.request().postDataJSON();
        if (body.reset) { history = []; await route.fulfill({ json: { status: 'reset' } }); return; }
        expect(body.simulation).toBe(true);
        expect(body.client_id).toBe('client');
        expect(body.channel_id).toBe('channel');
        const previous = history.at(-1)?.answer ?? null;
        history.push({ question: body.text, answer: `Ответ ${history.length + 1}` });
        await route.fulfill({ json: { status: 'completed', release: body.release, client_id: body.client_id, channel_id: body.channel_id, history, state: { stage: 'qualify', slots: { city: 'Воронеж' } }, inspector: { intent: 'unclear', client: 'Анна', channel: 'Сайт', previous_answer: previous, goal: { kind: 'ask_slot', slot: 'city', facts: [], trigger: null, allowed_tools: [] }, executed_tools: [], guard: { verdict: 'ok', issues: [], warnings: [] } } } });
    });
    await page.goto(url);
    await page.getByLabel('Клиент', { exact: true }).selectOption('client');
    await page.getByLabel('Канал', { exact: true }).selectOption('channel');
    await page.getByLabel('Версия знаний').selectOption('published');
    await page.getByLabel('Сообщение клиента').fill('Первый вопрос');
    await page.getByRole('button', { name: 'Получить ответ' }).click();
    await expect(page.getByLabel('История диалога')).toContainText('Ответ 1');
    await page.getByLabel('Сообщение клиента').fill('Второй вопрос');
    await page.getByRole('button', { name: 'Получить ответ' }).click();
    await expect(page.getByLabel('История диалога')).toContainText('Ответ 2');
    await page.getByText('Внутренности хода').click();
    await expect(page.getByText('Предыдущий ответ: Ответ 1')).toBeVisible();
    await expect(page.getByText(/Воронеж/)).toBeVisible();
    await expect(page.getByText('Клиент: Анна · канал: Сайт')).toBeVisible();
    await mkdir('.local/screenshots/SP-E4-10Q', { recursive: true });
    await page.screenshot({ path: '.local/screenshots/SP-E4-10Q/sandbox.png', fullPage: true });
    await page.route('**/seller/quality/scenarios', route => route.abort('failed'));
    const scenarioRequest = page.waitForRequest(request => request.method() === 'POST' && request.url().endsWith('/seller/quality/scenarios'));
    await page.getByRole('button', { name: 'Сохранить как контрольный сценарий' }).click();
    const scenario = (await scenarioRequest).postDataJSON();
    expect(scenario.first_message).toBe('Первый вопрос');
    expect(scenario.behavior).toContain('Второй вопрос');
    await expect(page.getByText('Нет связи. Сценарий не сохранён.')).toBeVisible();
    await page.getByRole('button', { name: 'Сбросить диалог' }).click();
    await expect(page.getByText('Диалог пока пуст.')).toBeVisible();
});

test('песочница работает на основных ширинах и в трёх темах', async ({ page }) => {
    for (const width of [1440, 1024, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(url);
        for (const theme of ['neutral', 'confectionery', 'pyro']) {
            await page.evaluate(value => document.documentElement.setAttribute('data-theme', value), theme);
            await expect(page.getByRole('heading', { name: 'Песочница знаний' })).toBeVisible();
            await expect(page.getByLabel('Сообщение клиента')).toBeVisible();
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        }
        await page.keyboard.press('Tab');
        await expect(page.locator(':focus')).toBeVisible();
    }
});
