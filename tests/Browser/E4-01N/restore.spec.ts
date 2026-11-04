import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E4-01N/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E4-01N/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('выбор файла для восстановления',async({page})=>{await page.goto(url);await expect(page.getByText('Проверка использует политику', {exact:false})).toBeVisible();const input=page.getByLabel('Восстановить копию из JSON');const button=page.getByRole('button',{name:'Восстановить из файла'});await expect(button).toBeDisabled();await input.setInputFiles({name:'backup.json',mimeType:'application/json',buffer:Buffer.from('{}')});await expect(button).toBeEnabled();await input.scrollIntoViewIfNeeded();await mkdir('.local/screenshots/sp-e4-01n',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e4-01n/restore.png',fullPage:true});});

test('извлечённые поля и отсутствие фикстуры видны в прогоне',async({page})=>{await page.goto(new URL('/tests/Browser/E4-01N/run.html',url).href);await expect(page.getByText('Извлечённые поля: Город: Воронеж')).toBeVisible();await expect(page.getByText('Разбор хода недоступен: нет проверенной фикстуры.')).toBeVisible();await page.screenshot({path:'.local/screenshots/sp-e4-01n/run.png',fullPage:true});});
