import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.setTimeout(60000);let server:ViteDevServer;let url:string;
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E1-09C/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E1-09C/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('ссылка на объект и полная карточка',async({page})=>{await page.setViewportSize({width:1440,height:900});await page.goto(url);const id='01ARZ3NDEKTSV4RRFFQ69G5FAV';await expect(page.getByRole('link',{name:'Диалог '+id})).toHaveAttribute('href','/seller/dialogs/'+id);await expect(page.getByRole('cell',{name:'Пример бренда',exact:true})).toBeVisible();await expect(page.getByRole('cell',{name:'Вся компания',exact:true})).toBeVisible();await expect(page.getByRole('link',{name:'unsafe/path'})).toHaveCount(0);await page.getByRole('button',{name:'Действия с записью'}).first().click();await page.getByRole('button',{name:'Детали действия',exact:true}).click();await expect(page.getByRole('link',{name:'Открыть полностью'})).toHaveAttribute('href','/seller/dialogs/'+id);await page.screenshot({path:'.local/runtime/e1-09c-journal.png',fullPage:true});});


