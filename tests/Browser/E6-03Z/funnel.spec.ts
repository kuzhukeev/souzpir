import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E6-03Z/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E6-03Z/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('цель стадии и закрывающие поля берутся из сохранённых настроек, не из заглушки',async({page})=>{
 await page.goto(url);
 const stage=page.getByRole('listitem').filter({has:page.getByRole('heading',{name:'Квалификация'})});
 await stage.getByText('Условия и действия').click();
 await expect(stage.getByText('Узнать: Город')).toBeVisible();
 await expect(stage.getByText('Поля, закрывающие стадию')).toBeVisible();
 await expect(stage.getByText('Данные не подтверждены')).toHaveCount(0);
 await expect(stage.getByText('Не указана в опубликованной политике')).toHaveCount(0);
 await expect(page.getByText('не более 3 реплик подряд с вопросом')).toBeVisible();
 await mkdir('.local/screenshots/SP-E6-03Z', {recursive: true});
 await page.screenshot({path: '.local/screenshots/SP-E6-03Z/funnel.png', fullPage: true});
});
