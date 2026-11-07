import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });

let server: ViteDevServer;
let fixture: string;
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E6-09D/vite.config.ts', cacheDir: '.local/runtime/vite-e609d', server: { host: '127.0.0.1', port: 0 } });
  await server.listen();
  fixture = new URL('/tests/Browser/E6-09D/ui-fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('форма вебхука и три сопоставления доступны на экране CRM', async ({ page }) => {
  for (const [width, theme] of [[1440, 'neutral'], [1024, 'pyro'], [390, 'confectionery']] as const) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`${fixture}?theme=${theme}`);
    await expect(page.getByLabel('URL вебхука')).toBeVisible();
    await expect(page.getByLabel('Секрет (64 шестнадцатеричных символа)')).toHaveAttribute('type', 'password');
    await page.getByLabel('URL вебхука').fill('https://crm.example.test/hook');
    await page.getByLabel('Секрет (64 шестнадцатеричных символа)').fill('a'.repeat(64));
    await page.getByRole('checkbox', { name: 'Контакт получен' }).check();
    await expect(page.getByRole('button', { name: 'Сохранить вебхук' })).toBeEnabled();
    await expect(page.getByRole('heading', { name: 'Сопоставление полей', level: 2 })).toBeVisible();
    await expect(page.getByLabel('Поле EYON 1')).toHaveValue('name');
    await expect(page.getByLabel('Поле CRM').first()).toHaveValue('name');
    await page.getByLabel('Поле CRM').first().fill('customer');
    await expect(page.getByRole('button', { name: 'Сохранить сопоставление' })).toBeEnabled();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    if (width === 1440) {
      await mkdir('.local/screenshots/SP-E6-09D', { recursive: true });
      await page.screenshot({ path: '.local/screenshots/SP-E6-09D/crm.png', fullPage: true });
    }
  }
});
