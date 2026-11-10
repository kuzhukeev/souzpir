import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-10I/vite.config.ts', cacheDir: '.local/runtime/vite-e4-10i', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E4-10I/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });

test('сохранение контрольного сценария передаёт все ходы диалога и сообщает о полном воспроизведении', async ({ page }) => {
  let body: Record<string, unknown> | null = null;
  let pageProps: Record<string, unknown> = {};
  await page.goto(url);
  pageProps = JSON.parse(await page.evaluate(() => document.querySelector('script[data-page]')!.textContent!)).props;
  await page.route('**/seller/quality/scenarios', async route => {
    if (route.request().method() !== 'POST') return route.continue();
    body = route.request().postDataJSON();
    await route.fulfill({ status: 200, headers: { 'X-Inertia': 'true' }, json: { component: 'Fixture', props: pageProps, url: new URL(url).pathname, version: null } });
  });
  await expect(page.getByRole('listitem').filter({ hasText: 'Батареи салютов и фонтаны' })).toBeVisible();
  await page.getByRole('button', { name: 'Сохранить как контрольный сценарий' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'воспроизводятся все сохранённые реплики' })).toBeVisible();
  expect(body).not.toBeNull();
  const behavior = (body as unknown as { behavior: string }).behavior;
  expect(behavior).toContain('Клиент: Нужна оптовая поставка для магазина в Казани.');
  expect(behavior).toContain('Клиент: Батареи салютов и фонтаны. Можно получить прайс?');
  expect((body as unknown as { max_turns: number }).max_turns).toBe(2);
  await mkdir('.local/screenshots/SP-E4-10I', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E4-10I/sandbox.png', fullPage: true });
});
