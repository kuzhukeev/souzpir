import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
let server: ViteDevServer;
let url: string;
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E8-18E/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  url = new URL('/tests/Browser/E8-18E/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test('компании, рекомендации и подтверждённые факты', async ({ page }) => {
  const revision = 'a'.repeat(64);
  const sections = { site: null, competitors: [{ name: 'Альфа', answers: 2, is_brand: false, models: ['openai'], strongest: ['price'] }], gaps: [{ intent: 'choose', text: 'Как выбрать?', companies: ['Альфа'], recommendations: [{ title: 'Обновить описание', why: 'По снимку' }] }], facts: [{ text: 'Верное утверждение', classification: 'correct', source: 'Документ', reason: '', provider: '' }, { text: 'Старая цена', classification: 'outdated', correction: 'Новая цена', source: null, reason: '', provider: '' }], sources: [], actions: [], changes: null };
  const data = { revision, confirmed: [], reports: [], report: { id: 'r', measurement_id: 'm', visibility: 25, visibility_kind: 'full', coverage: 100, share_of_voice: null, position: null, verified_errors: 1, providers: [], questions: 2, evidence_status: 'available', sections } };
  await page.route('**/vision/report/data**', route => route.fulfill({ json: data }));
  await page.route('**/vision/runs/revision', route => route.fulfill({ json: { revision } }));
  await page.goto(url);
  await expect(page.getByLabel('Упоминания компаний')).toBeVisible();
  await expect(page.getByRole('columnheader', { name: 'Сильнее всего' })).toBeVisible();
  await page.getByText('Выбор и сравнение', { exact: true }).last().click();
  await page.getByText('Что сделать', { exact: true }).click();
  await expect(page.getByText('Обновить описание')).toBeVisible();
  await expect(page.getByText('Верно · 1')).toBeVisible();
  await expect(page.getByText('Ошибки и устаревшее · 1')).toBeVisible();
  await expect(page.getByText('Новая цена')).toBeVisible();
  await expect(page.getByText('Документ')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e8-18e/report.png', fullPage: true });
});
