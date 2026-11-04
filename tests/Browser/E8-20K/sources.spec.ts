import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer; let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-20K/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E8-20K/fixture.html?screen=sources',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('карточка передаёт выбранную площадку в действие',async({page})=>{await page.goto(url+'&edit=1');await expect(page.getByText('Цитируют, вас нет')).toBeVisible();await page.getByRole('button',{name:'example.test'}).click();await page.getByRole('dialog').getByRole('button',{name:'Добавить в действия: завести/обновить профиль'}).click();expect(await page.evaluate(()=>(window as any).actionRequest)).toEqual({url:'/vision/actions',data:{title:'Проверить профиль на example.test',description:'Проверить присутствие бренда на площадке example.test.'}});await mkdir('.local/screenshots/sp-e8-20k',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e8-20k/source.png'});});
test('без права редактирования действие недоступно',async({page})=>{await page.goto(url);await page.getByRole('button',{name:'example.test'}).click();await expect(page.getByRole('dialog').getByRole('button',{name:'Добавить в действия: завести/обновить профиль'})).toHaveCount(0);});

test('ошибка сохранения показана в карточке',async({page})=>{await page.goto(url+'&edit=1&failure=1');await page.getByRole('button',{name:'example.test'}).click();await page.getByRole('dialog').getByRole('button',{name:'Добавить в действия: завести/обновить профиль'}).click();await expect(page.getByRole('dialog').getByRole('alert')).toContainText('Не удалось создать действие');});
