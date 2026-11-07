import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({configFile:'tests/Browser/E1-06J/vite.config.ts',server:{host:'127.0.0.1',port:0}}); await server.listen(); url = new URL('/tests/Browser/E1-06J/fixture.html',server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('инициалы, фото и ссылка уведомлений', async ({page}) => {
 for (const theme of ['neutral','pyro','confectionery']) for (const width of [1440,1024,390]) {
  await page.setViewportSize({width,height:900}); await page.goto(`${url}?theme=${theme}`);
  await expect(page.getByText('АИ', {exact:true})).toBeVisible();
  const link = page.getByRole('link',{name:'Настроить уведомления'});
  await expect(link).toHaveAttribute('href','/seller/settings/notifications');
  await link.focus(); await expect(link).toBeFocused();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
  if (width === 1440 && theme === 'neutral') { await mkdir('.local/screenshots/SP-E1-06J',{recursive:true}); await page.screenshot({path:'.local/screenshots/SP-E1-06J/member.png',fullPage:true}); }
 }
 await page.goto(`${url}?photo`);
 await expect(page.locator('img[src="/team/01J00000000000000000000003/avatar"]')).toHaveCount(1);
 await expect(page.getByText('АИ', {exact:true})).toHaveCount(0);
});
