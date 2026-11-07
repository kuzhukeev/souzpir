import { expect, test } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let fixture: string;
const revision = 'a'.repeat(64);
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E8-18F/vite.config.ts' });
  await server.listen();
  fixture = new URL('/tests/Browser/E8-18F/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

function report(candidates: {key: string; title: string; why: string; question_id: string}[]) {
  return { revision, confirmed: [], reports: [], report: { id: 'r', measurement_id: 'm', visibility: 25, visibility_kind: 'full', coverage: 100, share_of_voice: null, position: null, verified_errors: 0, providers: [], questions: 1, evidence_status: 'available', publication_candidates: candidates,
    sections: { site: null, competitors: [], gaps: [], facts: [], sources: [], actions: [], changes: null } } };
}

async function openReport(page: import('@playwright/test').Page, candidates: {key: string; title: string; why: string; question_id: string}[]) {
  await page.route('**/vision/report/data**', (route) => route.fulfill({ json: report(candidates) }));
  await page.route('**/vision/runs/revision', (route) => route.fulfill({ json: { revision } }));
  await page.goto(fixture);
  await page.getByRole('button', { name: 'Поделиться отчётом' }).click();
}

test('перед публикацией можно выбрать проверенное предложение', async ({ page }) => {
  await openReport(page, [{key: 'action:0', title: 'Изучить ответы на этот вопрос', why: 'Причины не установлены.', question_id: 'q1'}]);
  const choice = page.getByRole('checkbox', { name: /Изучить ответы на этот вопрос/ });
  await expect(choice).toBeVisible();
  await expect(choice).not.toBeChecked();
  await choice.check();
  await expect(choice).toBeChecked();
  await expect(page.getByText('Вопрос q1')).toBeVisible();
  await mkdir('.local/screenshots/SP-E8-18G', {recursive: true});
  await page.screenshot({path: '.local/screenshots/SP-E8-18G/publication-review.png', fullPage: true});
});

test('без проверенных предложений показано пустое состояние', async ({ page }) => {
  await openReport(page, []);
  await expect(page.getByText('Проверенных предложений для публикации нет.')).toBeVisible();
  await page.screenshot({path: '.local/screenshots/SP-E8-18G/publication-empty.png', fullPage: true});
});
