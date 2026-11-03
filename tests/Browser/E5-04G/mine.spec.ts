import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

test.setTimeout(60000);
let server: ViteDevServer;
let fixture: string;
test.beforeAll(async () => {
  server = await createServer({configFile: 'tests/Browser/E5-04G/vite.config.ts', server: {host: '127.0.0.1', port: 0}});
  await server.listen();
  fixture = new URL('/tests/Browser/E5-04G/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } });
test('карточка клиента показывает контакты и пропущенные поля', async ({page}) => {
  await page.setViewportSize({width:1440,height:900});
  await page.goto(fixture);
  const card = page.getByRole('complementary', {name:'Карточка клиента'});
  await expect(card.getByText('Телефон', {exact:true})).toBeVisible();
  await expect(card.getByText('anna@example.test')).toBeVisible();
  await expect(card.getByText('Источник', {exact:true})).toBeVisible();
  await expect(card.getByText('Телефон, Город')).toBeVisible();
  await page.screenshot({path:'.local/screenshots/sp-e5-04g/mine.png',fullPage:true});
});
