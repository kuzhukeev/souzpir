import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.use({
  launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
    ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH }
    : {},
});
test.beforeAll(async () => {
  server = await createServer({
    configFile: 'tests/Browser/E8-20X/vite.config.ts',
    server: { host: '127.0.0.1', port: 0, watch: null },
  });
  await server.listen();
  url = new URL('/tests/Browser/E8-20X/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => {
  await server?.close();
});
test('отдельный экран групп, выбор названий и ошибка пересчёта', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(url);
  await expect(page.getByRole('heading', { name: 'Объединить дубли названий' })).toBeVisible();
  await expect(page.getByText('Альфа старая')).toBeVisible();
  await page.getByRole('checkbox', { name: 'Выбрать Альфа', exact: true }).check();
  await page.getByRole('checkbox', { name: 'Выбрать ООО Альфа', exact: true }).check();
  await page.getByRole('button', { name: 'Объединить в…' }).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toContainText('Выбрано: 2');
  await expect(dialog).toContainText('исходный отчёт сохранится');
  await mkdir('.local/screenshots/SP-E8-20X', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E8-20X/merge.png' });
  await page.route('**/vision/competitors/merge', async (route) => route.abort());
  const request = page.waitForRequest(
    (req) => req.method() === 'POST' && req.url().endsWith('/vision/competitors/merge'),
  );
  await dialog.getByRole('button', { name: 'Подтвердить' }).click();
  expect((await request).postDataJSON()).toMatchObject({
    sources: ['ООО Альфа'],
    target: 'Альфа',
  });
  await expect(dialog.getByRole('alert')).toBeVisible();
  await dialog.getByRole('button', { name: 'Отмена' }).click();
  for (const width of [1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  }
});


test('пустое состояние, права и индикация пересчёта', async ({ page }) => {
  await page.goto(url + '?empty=1');
  await expect(page.getByText('Подтверждённого отчёта пока нет.')).toBeVisible();
  await page.goto(url + '?viewer=1');
  await expect(page.getByText('Нет прав на объединение названий.')).toBeVisible();
  await page.goto(url);
  await page.getByRole('checkbox', { name: 'Выбрать Альфа', exact: true }).check();
  await page.getByRole('checkbox', { name: 'Выбрать ООО Альфа', exact: true }).check();
  await page.getByRole('button', { name: 'Объединить в…' }).click();
  let release!: () => void;
  const wait = new Promise<void>((resolve) => { release = resolve; });
  await page.route('**/vision/competitors/merge', async (route) => { await wait; await route.abort(); });
  await page.getByRole('dialog').getByRole('button', { name: 'Подтвердить' }).click();
  await expect(page.getByRole('dialog').getByRole('button', { name: 'Пересчитываем…' })).toBeDisabled();
  release();
  await expect(page.getByRole('dialog').getByRole('alert')).toBeVisible();
});
