import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

test.setTimeout(60000);
let server: ViteDevServer;
let fixture: string;
test.beforeAll(async () => {
  server = await createServer({configFile: 'tests/Browser/E4-01F/vite.config.ts', server: {host: '127.0.0.1', port: 0}});
  await server.listen();
  fixture = new URL('/tests/Browser/E4-01F/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test.use({launchOptions:{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}});
test('результат и подтверждение видны на экране',async ({page})=>{
 await page.setViewportSize({width:1440,height:900});
 await page.goto(fixture);
 await expect(page.getByText('Тестовый прогон: пройдено 1, провалено 1.')).toBeVisible();
 await page.getByRole('button',{name:'Опубликовать',exact:true}).first().click();
 await expect(page.getByLabel('Подтверждаю публикацию при проваленных сценариях')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/sp-e4-01f/releases.png',fullPage:true});
});
