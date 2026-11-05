import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E5-04R/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E5-04R/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('назначенный менеджер видит доступность подсказки',async({page})=>{await page.goto(url);await expect(page.getByText('Генерация подсказки для этого режима пока недоступна.')).toBeVisible();await expect(page.getByRole('button',{name:'Сгенерировать подсказку'})).toBeDisabled();await expect(page.getByText('Проверенный вариант ответа')).toHaveCount(0);await mkdir('.local/screenshots/SP-E5-04R',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E5-04R/dialog.png',fullPage:true});});
test('состояние скрыто без права и для чужого назначения',async({page})=>{for(const param of ['viewer','other']){await page.goto(url+'?'+param);await expect(page.getByRole('region',{name:'Подсказка менеджеру'})).toHaveCount(0);}});
