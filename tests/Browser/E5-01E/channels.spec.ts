import {expect,test} from '@playwright/test';
import {createServer, type ViteDevServer} from 'vite';

test.setTimeout(60000);
let server: ViteDevServer;
let fixture: string;
test.beforeAll(async () => {
  server = await createServer({configFile:'tests/Browser/E5-01E/vite.config.ts',server:{host:'127.0.0.1',port:0}});
  await server.listen();
  fixture = new URL('/tests/Browser/E5-01E/fixture.html',server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test.use({launchOptions:{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}});
test('ссылки каналов и настройки Telegram',async ({page})=>{
 await page.setViewportSize({width:1440,height:900});
 await page.goto(fixture);
 await expect(page.locator('a[href="/seller/channels/email"]')).toBeVisible();
 await expect(page.locator('a[href="/integrations/api"]')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/sp-e5-01e/channels.png',fullPage:true});
 await page.goto(`${fixture}?settings`);
 await expect(page.getByText('Ожидаем подтверждение чата.')).toBeVisible();
 await expect(page.getByText('Статус: Не установлен')).toBeVisible();
 await expect(page.getByRole('radio',{name:'Подсказки'})).toBeVisible();
 await page.getByRole('radio',{name:'Подсказки'}).check();
 await expect(page.getByRole('radio',{name:'Подсказки'})).toBeChecked();
 await page.screenshot({path:'.local/screenshots/sp-e5-01e/telegram.png',fullPage:true});
});
