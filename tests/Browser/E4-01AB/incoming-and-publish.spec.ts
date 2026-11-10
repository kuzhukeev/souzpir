import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E4-01AB/vite.config.ts', cacheDir: '.local/runtime/vite-e401ab', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  url = new URL('/tests/Browser/E4-01AB/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

async function axeViolations(page: import('@playwright/test').Page) {
  await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
  return page.evaluate(async () => {
    const axe = (window as unknown as { axe: { run: (root: Document, options: object) => Promise<{ violations: { id: string; impact: string }[] }> } }).axe;
    return (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } })).violations.filter(v => v.impact === 'critical' || v.impact === 'serious').map(v => v.id);
  });
}

test('D-4.1/D-4.2: ссылки «Открыть диалог» не нарушают link-in-text-block на трёх ширинах', async ({ page }) => {
  await mkdir('.local/screenshots/SP-E4-01AB', { recursive: true });
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(url);
    await expect(page.getByRole('link', { name: 'Открыть диалог' }).first()).toHaveCSS('text-decoration-line', 'underline');
    const violations = await axeViolations(page);
    expect(violations).not.toContain('link-in-text-block');
    if (width === 1440) await page.screenshot({ path: '.local/screenshots/SP-E4-01AB/incoming-1440.png', fullPage: true });
  }
});

test('D-4.12: окно публикации содержит пояснение и отдельную кнопку «Отмена»', async ({ page }) => {
  await page.goto(url);
  await page.getByRole('button', { name: 'Опубликовать' }).click();
  await expect(page.getByText('Изменения черновика станут доступны боту.')).toBeVisible();
  const cancel = page.getByRole('button', { name: 'Отмена' });
  await expect(cancel).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E4-01AB/publish-modal.png', fullPage: true });
  await cancel.click();
  await expect(page.getByText('Изменения черновика станут доступны боту.')).toBeHidden();
});
