import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-10H/vite.config.ts', cacheDir: '.local/runtime/vite-e410h', server: {host: '127.0.0.1', port: 0} }); await server.listen(); url = new URL('/tests/Browser/E4-10H/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('выбор хода показывает данные именно этого ответа', async ({ page }) => {
    const history: object[] = [];
    await page.route('**/seller/knowledge/sandbox/reply', async route => {
        const body = route.request().postDataJSON(); const first = history.length === 0;
        const inspector = {intent: first ? 'delivery' : 'price_request', client: 'Анна', channel: 'Сайт', previous_answer: first ? null : 'Первый ответ', selected_facts: first ? ['Доставка по России'] : ['Цена по запросу'], selected_conditions: first ? {city: 'Воронеж'} : {}, goal: {kind: first ? 'ask_slot' : 'answer_only', slot: null, facts: [], trigger: null, allowed_tools: [], fewshot_tags: first ? ['delivery'] : []}, executed_tools: [], guard: {verdict: 'ok', issues: [], warnings: []}};
        const state = {stage: first ? 'qualify' : 'contact', slots: {city: first ? 'Воронеж' : 'Казань'}};
        history.push({question: body.text, answer: first ? 'Первый ответ' : 'Второй ответ', inspector, state});
        await route.fulfill({json: {status: 'completed', release: body.release, history, inspector, state}});
    });
    await page.goto(url);
    for (const text of ['Первый вопрос', 'Второй вопрос']) { await page.getByLabel('Сообщение клиента').fill(text); await page.getByRole('button', {name:'Получить ответ'}).click(); await expect(page.getByLabel('История диалога')).toContainText(text); }
    const inspector = page.getByRole('region', {name:'Внутренности хода'});
    await expect(inspector).toContainText('Предыдущий ответ: Первый ответ'); await expect(inspector).toContainText('Казань'); await expect(inspector).toContainText('Цена по запросу');
    await page.getByLabel('Выбранный ход').selectOption('0');
    await expect(inspector).toContainText('Предыдущий ответ: Нет'); await expect(inspector).toContainText('Воронеж'); await expect(inspector).toContainText('Доставка по России'); await expect(inspector).not.toContainText('Цена по запросу');
    await page.getByLabel('Выбранный ход').focus(); await page.keyboard.press('ArrowDown'); await expect(inspector).toContainText('Казань');
    await page.addScriptTag({path: 'node_modules/axe-core/axe.min.js'});
    for (const width of [1440, 1024, 390]) { await page.setViewportSize({width, height:900}); for (const theme of ['neutral','confectionery','pyro']) { await page.evaluate(value => document.documentElement.setAttribute('data-theme', value), theme); await expect(inspector).toBeVisible(); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true); } const violations = await page.evaluate(async () => { const axe = (window as unknown as {axe: {run: (context: string, options: object) => Promise<{violations: {impact: string}[]}>}}).axe; const result = await axe.run('section[aria-labelledby=turn-inspector-title]', {runOnly: {type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']}}); return result.violations.filter(item => ['critical', 'serious'].includes(item.impact)); }); expect(violations).toEqual([]); }
    await page.setViewportSize({width:1440,height:900}); await page.evaluate(() => document.documentElement.setAttribute('data-theme','neutral')); await mkdir('.local/screenshots/SP-E4-10H',{recursive:true}); await page.screenshot({path:'.local/screenshots/SP-E4-10H/inspector.png',fullPage:true});
});
