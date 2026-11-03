import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
let server: ViteDevServer; let fixture: string;
test.setTimeout(60000);
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E4-01G/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();fixture=new URL('/tests/Browser/E4-01G/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('конкретный прогон и подсказка про откат',async({page})=>{await page.goto(fixture);await expect(page.getByText('Сложные критерии считаются непроверенными', {exact:false})).toBeVisible();await expect(page.getByRole('link',{name:'Открыть прогон'}).first()).toHaveAttribute('href','/seller/knowledge/runs/01ARZ3NDEKTSV4RRFFQ69G5FAY');await expect(page.getByText('Откат создаёт новый черновик', {exact:false})).toBeVisible();await expect(page.getByRole('button',{name:'Создать черновик'})).toBeVisible();await page.screenshot({path:'.local/screenshots/sp-e4-01g/releases.png',fullPage:true});});
