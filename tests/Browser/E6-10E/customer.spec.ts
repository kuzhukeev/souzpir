import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir, writeFile } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E6-10E/vite.config.ts', cacheDir: '.local/runtime/vite-e610e-customer', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E6-10E/customer-fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('customer card shows published additional fields below the system data', async ({ page }) => {
 await page.goto(url);
 await expect(page.getByText('Марка и модель')).toBeVisible();
 await expect(page.getByText('Toyota Camry')).toBeVisible();
 await expect(page.getByRole('heading', { name: 'Остальные поля' })).toBeVisible();
 await mkdir('.local/screenshots/SP-E6-10E', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E6-10E/customer-card.png', fullPage: true });
});
test('D-3.2: no critical/serious axe violations on 1440/1024/390', async ({ page }) => {
 test.setTimeout(90000);
 const findings: Record<number, { id: string; impact: string; nodes: unknown[] }[]> = {};
 for (const width of [1440, 1024, 390]) {
  await page.setViewportSize({ width, height: 900 });
  await page.goto(url);
  await expect(page.getByText('Марка и модель')).toBeVisible();
  await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
  findings[width] = await page.evaluate(async () => {
   const axe = (window as unknown as { axe: { run: (root: Document, options: object) => Promise<{ violations: { id: string; impact: string; nodes: { target: unknown[] }[] }[] }> } }).axe;
   return (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } })).violations.filter(v => v.impact === 'critical' || v.impact === 'serious').map(v => ({ id: v.id, impact: v.impact, nodes: v.nodes.map(node => node.target) }));
  });
 }
 await writeFile('.local/screenshots/SP-E6-10E/customer-axe.json', JSON.stringify(findings, null, 2));
 expect(Object.values(findings).flat()).toEqual([]);
});
