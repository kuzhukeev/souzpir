import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-22F/vite.config.ts', cacheDir: '.local/runtime/vite-e822f', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E8-22F/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('показывает число вопросов и запланированную дату', async ({ page }) => {
 await page.route('**/vision/actions/effect', route => route.fulfill({ json: { items: [], next_measurement_at: '2026-12-05T12:00:00Z', recommendations: { a: { priority: 'Высокий', steps: ['Проверить'], question_ids: ['q1', 'q2'] } } } }));
 await page.goto(url); await expect(page.getByText('Поможет ответить на 2 вопроса')).toBeVisible();
 await expect(page.getByText(/после следующего замера/)).toContainText('05.12.2026'); await expect(page.getByText('q1, q2')).toHaveCount(0);
 await mkdir('.local/screenshots/SP-E8-22F', { recursive: true }); await page.screenshot({ path: '.local/screenshots/SP-E8-22F/actions.png', fullPage: true });
});
test('отправляет источник из критерия', async ({ page }) => {
 await page.goto(url + '?criteria'); await page.getByText('Контакты', { exact: true }).click();
 await page.route('**/vision/actions', route => route.abort());
 const posted = page.waitForRequest(req => req.method() === 'POST' && req.url().endsWith('/vision/actions'));
 await page.getByRole('button', { name: 'В действия' }).click(); expect((await posted).postDataJSON()).toMatchObject({ audit_id: '01J00000000000000000000003', criterion_id: '3' });
 await expect(page.getByRole('alert')).toContainText('Не удалось добавить действие');
});

test('история показывает даты сопоставимых замеров и ссылку в пустом состоянии', async ({ page }) => {
 await page.route('**/vision/actions/effect', route => route.fulfill({ json: { recommendations: {}, items: [{ id: 'a', completed_by_name: null, before: { date: '2026-10-01', visibility: 10 }, after: { date: '2026-11-02', visibility: 20 }, delta: 10 }] } }));
 await page.goto(url + '?history'); await expect(page.getByRole('columnheader', { name: 'Замер до → после' })).toBeVisible();
 await expect(page.getByRole('cell', { name: /01.10.2026.*02.11.2026/ })).toBeVisible();
 await expect(page.getByText(/не доказывает причинную связь/i).first()).toBeVisible();
 await page.goto(url + '?history&empty'); await expect(page.getByRole('link', { name: 'К действиям →' })).toHaveAttribute('href', '/vision/actions');
});
