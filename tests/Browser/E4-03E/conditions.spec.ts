import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
let server: ViteDevServer;
let fixture: string;
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E4-03E/vite.config.ts', cacheDir: '.local/runtime/vite-e403e', server: { host: '127.0.0.1', port: 0, watch: null } });
  await server.listen();
  fixture = new URL('/tests/Browser/E4-03E/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('дополнительные условия и настоящие связи фактов', async ({ page }) => {
  for (const [width, theme] of [[1440, 'neutral'], [1024, 'pyro'], [390, 'confectionery']] as const) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`${fixture}?theme=${theme}`);
    await page.getByLabel('Количество, шт.').fill('12');
    await page.getByLabel('Предоплата, %').fill('30');
    await page.getByLabel('Отсрочка новым клиентам').selectOption('no');
    await page.getByLabel('Условия наличия').fill('Уточнить у менеджера');
    await page.getByLabel('Дополнительные условия').fill('По запросу');
    await expect(page.getByRole('link', { name: 'Минимальная сумма {cond:min_order_rub}' })).toHaveAttribute('href', '/seller/knowledge/facts/01J00000000000000000000001');
    await expect(page.getByText('Связанных фактов в черновике нет.').first()).toBeVisible();
    await page.getByRole('button', { name: 'Сохранить условия' }).click();
    await expect(page.getByText('Изменения вступят в силу после публикации версии.').last()).toBeVisible();
    await page.getByRole('button', { name: 'Отмена' }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByText('Изменения вступят в силу после публикации версии.').last()).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    if (width === 1440) { await mkdir('.local/screenshots/SP-E4-03E', { recursive: true }); await page.screenshot({ path: '.local/screenshots/SP-E4-03E/conditions.png', fullPage: true }); }
  }
});
