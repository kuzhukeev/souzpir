import {test,expect} from '@playwright/test';import {createServer,type ViteDevServer} from 'vite';import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E7-04M/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E7-04M/fixture.html',server.resolvedUrls!.local[0]).href;});test.afterAll(async()=>{await server?.close();});
test('источники и каналы: таблицы каналов и страниц сайта с UTM',async({page})=>{
  await page.goto(url);
  await page.getByRole('button',{name:'Источники и каналы'}).click();
  await expect(page.getByRole('rowheader',{name:'Сайт'})).toBeVisible();
  await expect(page.getByText('WhatsAppСкоро')).toBeVisible();
  await expect(page.getByRole('rowheader',{name:'/catalog'})).toBeVisible();
  await expect(page.getByRole('cell',{name:'utm_source=search'})).toBeVisible();
  await mkdir('.local/screenshots/SP-E7-04M',{recursive:true});
  await page.screenshot({path:'.local/screenshots/SP-E7-04M/channels.png',fullPage:true});
  await page.goto(url+'?empty');
  await page.getByRole('button',{name:'Источники и каналы'}).click();
  await expect(page.getByText('Данных по страницам пока нет')).toBeVisible();
  await page.screenshot({path:'.local/screenshots/SP-E7-04M/channels-empty.png',fullPage:true});
});
