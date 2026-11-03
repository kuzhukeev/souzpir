import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let fixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E8-15D/vite.config.ts' });
  await server.listen();
  fixture = new URL('/tests/Browser/E8-15D/pages-fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

const question = { id: 'q1', text: 'Где найти качественные услуги рядом?', intent: 'choose', status: 'proposed', selected: true, origin: 'manual', can_delete: true };
const data = { candidates: [question], active_set: null, history: [], limits: { questions: '20', models: '3' }, models: [{ id: 'google/gemini-2.5-flash', provider: 'gemini', status: 'active' }], generation: { status: 'blocked' }, can_edit: true, has_profile: true };

test('question table has action menu', async ({ page }) => {
  await page.route('**/vision/questions/data', route => route.fulfill({ json: data }));
  await page.goto(`${fixture}?screen=index`);
  await page.getByLabel('Действия с вопросом').click();
  await expect(page.getByRole('button', { name: 'Изменить' })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E8-15D/questions.png', fullPage: true });
});

test('approval exposes day and local time only for explicit schedule', async ({ page }) => {
  await page.route('**/vision/questions/data', route => route.fulfill({ json: data }));
  await page.goto(`${fixture}?screen=approve`);
  await page.getByLabel('google/gemini-2.5-flash').check();
  await expect(page.getByLabel('День недели')).toHaveCount(0);
  await page.getByRole('checkbox', { name: 'Включить автоматическое расписание' }).check();
  await expect(page.getByLabel('День недели')).toHaveValue('1');
  await expect(page.getByLabel('Время бренда')).toHaveValue('09:00');
  await expect(page.getByLabel('Частота')).toHaveValue('weekly');
  await page.screenshot({ path: '.local/screenshots/SP-E8-15D/approve.png', fullPage: true });
});

test('history action menu links to measurements', async ({ page }) => {
  const history = [{ id: 'v1', version: 1, size: 1, checksum: 'a'.repeat(64), providers: { gemini: 'google/gemini-2.5-flash' }, frequency: 'weekly', approved_by: 'u', approver_name: 'Иван', created_at: '2026-10-31T12:00:00Z', active: true, questions: [question], measurements: [{ id: 'm1', number: 1, status: 'done', created_at: '2026-11-01T12:00:00Z' }] }];
  await page.route('**/vision/questions/data', route => route.fulfill({ json: { ...data, history } }));
  await page.goto(`${fixture}?screen=history`);
  await page.getByLabel('Действия с версией 1').click();
  await expect(page.getByRole('link', { name: 'Посмотреть замеры' })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E8-15D/history.png', fullPage: true });
});
