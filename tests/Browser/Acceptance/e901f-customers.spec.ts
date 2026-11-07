import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir, writeFile } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.skip(process.env.RUN_ACCEPTANCE_E901F_VISUAL !== '1', 'Manual visual audit; run explicitly.');
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E5-05F/vite.config.ts', cacheDir: '.local/runtime/vite-e901f-customers', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  url = new URL('/tests/Browser/E5-05F/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('D-3.1: три ширины и проверка доступности', async ({ page }) => {
  test.setTimeout(90000);
  await page.route('**/*', route => new URL(route.request().url()).origin === new URL(url).origin ? route.continue() : route.abort('blockedbyclient'));
  await mkdir('.local/screenshots/SP-E9-01F', { recursive: true });
  const findings: Record<number, { id: string; impact: string; nodes: unknown[] }[]> = {};
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(url);
    await expect(page.getByRole('heading', { name: /Клиенты/ })).toBeVisible();
    await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
    findings[width] = await page.evaluate(async () => {
      const axe = (window as unknown as { axe: { run: (root: Document, options: object) => Promise<{ violations: { id: string; impact: string; nodes: { target: unknown[] }[] }[] }> } }).axe;
      return (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } })).violations.filter(v => v.impact === 'critical' || v.impact === 'serious').map(v => ({ id: v.id, impact: v.impact, nodes: v.nodes.map(node => node.target) }));
    });
    await page.screenshot({ path: `.local/screenshots/SP-E9-01F/d31-${width}.png`, fullPage: true, animations: 'disabled' });
  }
  await writeFile('.local/screenshots/SP-E9-01F/d31-axe.json', JSON.stringify(findings, null, 2));
  expect(Object.values(findings).flat()).toEqual([]);
});
