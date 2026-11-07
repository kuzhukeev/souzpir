import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
import { readFileSync } from 'node:fs';
const profiles = JSON.parse(readFileSync('tests/fixtures/E6-10D/profiles.json', 'utf8')) as Record<'service' | 'school', { fields: { name: string }[] }>;

let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E6-10D/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  url = new URL('/tests/Browser/E6-10D/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('показывает опубликованные поля автосервиса и школы', async ({ page }) => {
  await mkdir('.local/screenshots/SP-E6-10D', { recursive: true });
  for (const key of ['service', 'school'] as const) {
    await page.goto(`${url}?${key}`);
    for (const field of profiles[key].fields) {
      await expect(page.getByRole('row').filter({ hasText: field.name })).toBeVisible();
    }
    await page.screenshot({ path: `.local/screenshots/SP-E6-10D/${key}.png`, fullPage: true });
  }
});
