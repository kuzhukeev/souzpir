import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
test.setTimeout(60000);let server:ViteDevServer;let url:string;
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-21/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E8-21/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('оценка и диапазон группы при неизвестном критерии',async({page})=>{await page.setViewportSize({width:1440,height:900});await page.goto(url);await expect(page.getByText('Хорошая готовность')).toBeVisible();await expect(page.getByText('7.5–12.5 / 20 · проверено 3 из 4')).toBeVisible();await expect(page.getByLabel('Баллы группы: Техническая доступность')).toHaveAttribute('value','7.5');await page.screenshot({path:'.local/runtime/e8-21-score.png',fullPage:true});});
