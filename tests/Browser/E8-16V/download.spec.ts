import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
const revision = 'a'.repeat(64);
const data = {
  revision,
  confirmed: [],
  reports: [],
  report: {
    id: 'r',
    measurement_id: 'm',
    visibility: 25,
    visibility_kind: 'full',
    coverage: 100,
    share_of_voice: null,
    position: null,
    verified_errors: 0,
    providers: [],
    questions: 2,
    evidence_status: 'available',
    sections: {
      site: null,
      competitors: [],
      gaps: [],
      facts: [],
      sources: [],
      actions: [],
      changes: null,
    },
  },
};
test.use({
  launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
    ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH }
    : {},
});
test.beforeAll(async () => {
  server = await createServer({
    configFile: 'tests/Browser/E8-16V/vite.config.ts',
    server: { host: '127.0.0.1', port: 0, watch: null },
  });
  await server.listen();
  url = new URL('/tests/Browser/E8-16V/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => {
  await server?.close();
});
test('меню доступно с клавиатуры и печатает текущий отчёт', async ({ page }) => {
  await page.route('**/vision/report/data**', (route) => route.fulfill({ json: data }));
  await page.route('**/vision/runs/revision', (route) => route.fulfill({ json: { revision } }));
  await page.addInitScript(() => {
    window.print = () => {
      document.body.dataset.printed = 'yes';
    };
  });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(url);
  const trigger = page.getByRole('button', { name: 'Скачать отчёт' });
  await expect(trigger).toBeVisible();
  await trigger.focus();
  await page.keyboard.press('Enter');
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  await expect(page.getByRole('menuitem', { name: 'Печать / PDF' })).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(trigger).toBeFocused();
  await expect(trigger).toHaveAttribute('aria-expanded', 'false');
  await page.keyboard.press('Enter');
  await mkdir('.local/screenshots/SP-E8-16V', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E8-16V/menu.png' });
  await page.emulateMedia({ media: 'print' });
  await expect(trigger).toBeHidden();
  await page.emulateMedia({ media: 'screen' });
  if ((await trigger.getAttribute('aria-expanded')) === 'false') await trigger.click();
  await page.getByRole('menuitem', { name: 'Печать / PDF' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('body')).toHaveAttribute('data-printed', 'yes');
  await expect(trigger).toHaveAttribute('aria-expanded', 'false');
  for (const width of [1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await expect(trigger).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  }
});
test('при отсутствии отчёта и ошибке загрузки меню недоступно', async ({ page }) => {
  await page.route('**/vision/report/data**', (route) =>
    route.fulfill({ json: { revision, confirmed: [], reports: [], report: null } }),
  );
  await page.route('**/vision/runs/revision', (route) => route.fulfill({ json: { revision } }));
  await page.goto(url);
  await expect(
    page.getByText(
      'Пока нет подтверждённого замера. Завершите и подтвердите замер на странице замеров.',
    ),
  ).toBeVisible();
  await expect(page.getByRole('button', { name: 'Скачать отчёт' })).toHaveCount(0);
  await page.unroute('**/vision/report/data**');
  await page.route('**/vision/report/data**', (route) => route.abort());
  await page.goto(url);
  await expect(page.getByRole('alert')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Скачать отчёт' })).toHaveCount(0);
});

test('пока отчёт загружается, скачивание недоступно', async ({ page }) => {
  let release!: () => void;
  const waitForRelease = new Promise<void>((resolve) => { release = resolve; });
  await page.route('**/vision/report/data**', async (route) => { await waitForRelease; await route.fulfill({ json: data }); });
  await page.route('**/vision/runs/revision', (route) => route.fulfill({ json: { revision } }));
  await page.goto(url);
  await expect(page.getByText('Загружаем отчёт…')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Скачать отчёт' })).toHaveCount(0);
  release();
  await expect(page.getByRole('button', { name: 'Скачать отчёт' })).toBeVisible();
});
