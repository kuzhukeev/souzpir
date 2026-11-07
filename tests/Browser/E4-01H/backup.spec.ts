import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
let server: ViteDevServer; let fixture: string;
test.setTimeout(60000);
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E4-01G/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();fixture=new URL('/tests/Browser/E4-01G/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('черновик можно скачать и восстановить из файла',async({page})=>{
 await page.goto(fixture);
 await expect(page.getByRole('link',{name:'Скачать копию черновика'})).toHaveAttribute('href','/seller/knowledge/01ARZ3NDEKTSV4RRFFQ69G5FAV/backup');
 await expect(page.getByLabel('Восстановить копию из JSON')).toBeVisible();
 await expect(page.getByRole('button',{name:'Восстановить из файла'})).toBeDisabled();
 await expect(page.getByRole('button',{name:'Создать черновик'})).toBeDisabled();
 await expect(page.getByText('смысловые критерии доступны при наличии проверенных локальных fake-фикстур',{exact:false})).toBeVisible();
 await page.screenshot({path:'.local/screenshots/SP-E4-01H/releases.png',fullPage:true});
});
