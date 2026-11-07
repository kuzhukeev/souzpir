import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-09C/vite.config.ts', cacheDir: '.local/runtime/vite-e409c', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E4-09C/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('инспектор показывает выполненный тестовый инструмент', async ({ page }) => {
    await page.route('**/seller/knowledge/sandbox/reply', route => route.fulfill({ json: { status: 'completed', release: 'published', client_id: null, channel_id: null, history: [{ question: 'Мне нужен человек, не бот.', answer: 'В тестовом сценарии запрос передан менеджеру.' }], state: { stage: 'handoff', slots: {} }, inspector: { intent: 'want_human', extraction_source: 'fixture', response_source: 'reviewed_fixture', previous_answer: null, goal: { kind: 'handoff', slot: null, facts: [], trigger: null, allowed_tools: ['escalate'] }, executed_tools: [{ name: 'escalate', status: 'fake_completed' }], guard: { verdict: 'ok', issues: [], warnings: [] } } } }));
    await page.goto(url);
    await page.getByLabel('Версия знаний').selectOption('published');
    await page.getByLabel('Сообщение клиента').fill('Мне нужен человек, не бот.');
    await page.getByRole('button', { name: 'Получить ответ' }).click();
    await page.getByText('Внутренности хода').click();
    await expect(page.getByRole('region',{name:'Внутренности хода'}).locator('xpath=.//dt[normalize-space()="Действия"]/following-sibling::dd[1]')).toHaveText('escalate (тест)');
    await expect(page.getByText('Источник ответа: записанная фикстура')).toBeVisible();
    await mkdir('.local/screenshots/SP-E4-09C', { recursive: true });
    await page.screenshot({ path: '.local/screenshots/SP-E4-09C/sandbox.png', fullPage: true });
});
