import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
let server: ViteDevServer;
let url: string;
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-07C/vite.config.ts', cacheDir: '.local/runtime/vite-e407c', server: { host: '127.0.0.1', port: 0, watch: null } }); await server.listen(); url = new URL('/tests/Browser/E4-07C/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
const inspector = (intent: string, previous: string | null, tools: { name: string; status: 'fake_completed' }[] = []) => ({ intent, extraction_source: 'fixture', response_source: 'fixture', previous_answer: previous, slot_updates: {}, selected_facts: ['Минимальная сумма 20 тыс.'], selected_conditions: { min_order_rub: 20000 }, goal: { kind: 'answer_only', slot: null, facts: ['min_order'], trigger: null, allowed_tools: ['send_price'] }, executed_tools: tools, guard: { verdict: 'ok', issues: [], warnings: [] } });
test('выбор хода показывает его состояние и только выполненные инструменты', async ({ page }) => {
  let calls = 0;
  const first = { question: 'Первый вопрос', answer: 'Первый ответ', created_at: '2026-11-07T12:00:00Z', state: { stage: 'open', slots: { city: 'Казань' } }, inspector: inspector('ask_min_order', null) };
  const second = { question: 'Второй вопрос', answer: 'Второй ответ', created_at: '2026-11-07T12:01:00Z', state: { stage: 'need', slots: { city: 'Казань', purpose: 'магазин' } }, inspector: inspector('ask_delivery', 'Первый ответ', [{ name: 'send_price', status: 'fake_completed' }]) };
  await page.route('**/seller/knowledge/sandbox/reply', route => { calls++; return route.fulfill({ json: { status: 'completed', release: 'draft', client_id: null, channel_id: null, history: calls === 1 ? [first] : [first, second], state: calls === 1 ? first.state : second.state, inspector: calls === 1 ? first.inspector : second.inspector } }); });
  await page.goto(url);
  await page.getByLabel('Сообщение клиента').fill(first.question);
  await page.getByRole('button', { name: 'Получить ответ' }).click();
  await page.getByLabel('Сообщение клиента').fill(second.question);
  await page.getByRole('button', { name: 'Получить ответ' }).click();
  await page.getByText('Внутренности хода').click();
  await expect(page.getByText('Предыдущий ответ: Первый ответ')).toBeVisible();
  await expect(page.getByRole('region',{name:'Внутренности хода'}).locator('xpath=.//dt[normalize-space()="Действия"]/following-sibling::dd[1]')).toHaveText('send_price (тест)');
  await page.getByLabel('Выбранный ход').selectOption('0');
  await expect(page.getByRole('region',{name:'Внутренности хода'}).locator('xpath=.//dt[normalize-space()="Намерение клиента"]/following-sibling::dd[1]')).toContainText('ask_min_order');
  await expect(page.getByRole('region',{name:'Внутренности хода'}).locator('xpath=.//dt[normalize-space()="Стадия"]/following-sibling::dd[1]')).toHaveText('Начало диалога');
  await expect(page.getByRole('region',{name:'Внутренности хода'}).getByText('tools: send_price', { exact: false })).toBeVisible();
  await expect(page.getByRole('region',{name:'Внутренности хода'}).locator('xpath=.//dt[normalize-space()="Действия"]/following-sibling::dd[1]')).toHaveText('Нет (тестовый режим)');
  await expect(page.getByRole('region',{name:'Внутренности хода'}).locator('xpath=.//dt[normalize-space()="Выбранные факты и условия"]/following-sibling::dd[1]')).toContainText('min_order_rub: 20000');
  await mkdir('.local/screenshots/SP-E4-07C', { recursive: true });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.screenshot({ path: '.local/screenshots/SP-E4-07C/sandbox.png', fullPage: true });
  await page.route('**/seller/quality/scenarios', route => route.abort('failed'));
  const saved = page.waitForRequest(request => request.method() === 'POST' && request.url().includes('/seller/quality/scenarios'));
  await page.getByRole('button', { name: 'Сохранить как контрольный сценарий' }).click();
  const body = (await saved).postDataJSON();
  expect(body.first_message).toBe(first.question);
  expect(body.behavior).toContain(second.question);
  await expect(page.getByRole('status').filter({ hasText: 'Нет связи' })).toBeVisible();
});

test('пустое состояние и адаптивность сохраняются в трёх темах', async ({ page }) => {
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(url);
    for (const theme of ['neutral', 'pyro', 'confectionery']) {
      await page.evaluate(value => document.documentElement.setAttribute('data-theme', value), theme);
      await expect(page.getByText('Диалог пока пуст.')).toBeVisible();
      await expect(page.getByLabel('Сообщение клиента')).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    }
    await page.getByLabel('Сообщение клиента').focus();
    await expect(page.getByLabel('Сообщение клиента')).toBeFocused();
  }
  await page.route('**/seller/knowledge/sandbox/reply', async route => { await new Promise(resolve => setTimeout(resolve, 500)); await route.abort('failed'); });
  await page.getByLabel('Сообщение клиента').fill('Проверка');
  await page.getByRole('button', { name: 'Получить ответ' }).click();
  await expect(page.getByRole('button', { name: 'Готовим ответ…' })).toBeDisabled();
  await expect(page.getByRole('alert')).toContainText('Не удалось получить ответ');
  await expect(page.getByLabel('Сообщение клиента')).toHaveValue('Проверка');
});
