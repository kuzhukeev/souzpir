import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E6-03F/vite.config.ts',cacheDir:'.local/runtime/vite-e603f',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E6-03F/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('реакции отображают опубликованные фразы',async({page})=>{await page.goto(url);await expect(page.getByText('Больше не побеспокою.')).toBeVisible();await expect(page.getByText('Передам вопрос менеджеру.')).toBeVisible();await expect(page.getByRole('link',{name:'Редактировать примеры в базе знаний'})).toHaveAttribute('href','/seller/knowledge/fewshot');await mkdir('.local/screenshots/SP-E6-03F',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E6-03F/reactions.png',fullPage:true});});
test('пустые реакции и просмотр без права изменения',async({page})=>{await page.goto(url+'?empty&readonly');await expect(page.getByText('Примеры не заданы.')).toHaveCount(5);await expect(page.getByRole('link',{name:'Редактировать примеры в базе знаний'})).toHaveCount(0);});
