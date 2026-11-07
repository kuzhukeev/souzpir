import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E6-03O/vite.config.ts',cacheDir:'.local/runtime/vite-e603o',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E6-03O/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('исключение этапа сохраняется, обязательное исключение остаётся',async({page})=>{await page.goto(url);const choices=page.getByRole('group',{name:'Дополнительные исключения по этапам'});await choices.getByLabel('Потребность').check();await expect(page.getByLabel('Спам')).toBeDisabled();await mkdir('.local/screenshots/SP-E6-03O',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E6-03O/exceptions.png',fullPage:true});let payload:Record<string,unknown>|null=null;await page.route('**/seller/settings/followups',async route=>{payload=route.request().postDataJSON();await route.abort();});const form=choices.locator('xpath=ancestor::form');await form.getByRole('button',{name:'Сохранить',exact:true}).click();await expect.poll(()=>payload?.excluded_stages).toEqual(['need']);});
