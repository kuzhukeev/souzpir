import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let vite: ViteDevServer;
const fixture = 'http://127.0.0.1:5305/tests/Browser/E8-15B/pages-fixture.html';
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { vite = await createServer({ configFile: 'tests/Browser/E8-15B/vite.config.ts' }); await vite.listen(); });
test.afterAll(async () => { await vite?.close(); });

test('карточка набора показывает пример данных, история — номера замеров', async ({ page }) => {
  const question = { id: 'q1', text: 'Как выбрать услуги?', intent: 'choose' };
  const active = { id: 'set', version: 2, questions: [question], size: 1, checksum: 'a'.repeat(64), providers: { gemini: 'google/gemini' }, frequency: 'weekly', active: true, approved_by: 'u', approver_name: 'Иван', created_at: '2026-10-31T12:00:00Z', measurements: [{ id: 'measurement-a', number: 12, status: 'completed', created_at: '2026-11-01T12:00:00Z' }, { id: 'measurement-b', number: 13, status: 'completed', created_at: '2026-11-02T12:00:00Z' }] };
  const data = { candidates: [], active_set: active, history: [active], limits: { questions: null, models: null }, models: [], generation: { status: 'blocked' }, can_edit: true, has_profile: true };
  await page.route('**/vision/questions/data', route => route.fulfill({ json: data }));
  await page.goto(`${fixture}?screen=active`);
  await expect(page.getByText('Пример данных')).toBeVisible();
  await expect(page.getByText('Вопрос: Как выбрать услуги?')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/e8-15b/active.png', fullPage: true });
  await page.goto(`${fixture}?screen=history`);
  await expect(page.getByRole('cell', { name: '№ 12, № 13' })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/e8-15b/history.png', fullPage: true });
});
