import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-18J/vite.config.ts', cacheDir: '.local/runtime/vite-e818j', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E8-18J/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('report waits for incomplete questionnaire', async ({ page }) => {
 const revision = 'a'.repeat(64);
 await page.route('**/vision/report/data**', route => route.fulfill({ json: { revision, questionnaire_complete: false, confirmed: [], reports: [], report: null } }));
 await page.route('**/vision/runs/revision', route => route.fulfill({ json: { revision } }));
 await page.goto(url);
 await expect(page.getByText('Откроется после завершения анкеты.')).toBeVisible();
 await expect(page.getByRole('link', { name: 'Перейти к анкете →' })).toBeVisible();
 await mkdir('.local/screenshots/SP-E8-18J', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E8-18J/report.png', fullPage: true });
});
test('KPI shows delta in п.п. and action card shows priority', async ({ page }) => {
 const revision = 'a'.repeat(64);
 const sections = { site: null, competitors: [], gaps: [], facts: [], sources: [], source_distribution: [],
  actions: [{ title: 'Обновить сайт', why: 'Найден пробел', priority: 'Высокий' }],
  changes: { rows: [{ label: 'Видимость', before: 20, after: 26, delta: 6 }, { label: 'Доля голоса', before: 30, after: 24, delta: -6 }, { label: 'Средняя позиция', before: 3, after: 2.4, delta: -0.6 }], new_competitors: [], appeared: [], disappeared: [] } };
 const data = { revision, confirmed: [], reports: [], report: { id: 'r', measurement_id: 'm', visibility: 26, visibility_kind: 'full', coverage: 100, share_of_voice: 24, position: 2.4, verified_errors: 3, providers: [], questions: 2, evidence_status: 'available', sections } };
 await page.route('**/vision/report/data**', route => route.fulfill({ json: data }));
 await page.route('**/vision/runs/revision', route => route.fulfill({ json: { revision } }));
 await page.goto(url);
 await expect(page.getByText('+6 п.п.', { exact: true })).toBeVisible();
 await expect(page.getByText('-6 п.п.', { exact: true })).toBeVisible();
 await expect(page.locator('#actions').getByText('Приоритет: Высокий')).toBeVisible();
});
