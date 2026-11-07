import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.setTimeout(60000);
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-16S/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E8-16S/fixture.html?page=report',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('показатели и уточнения отражают сохранённые значения',async({page})=>{
 await page.goto(url);
 await expect(page.getByRole('heading',{name:'Отчёт о видимости в нейросетях'})).toBeVisible();
 await expect(page.getByRole('status').filter({hasText:'Уточнений ждут ответа: 1'})).toContainText('Для большей точности');
 await expect(page.locator('#overview')).toContainText('34 %');
 await expect(page.locator('#overview')).toContainText('15 %');
 await expect(page.getByRole('link',{name:'Вернуться к уточнениям →'})).toHaveAttribute('href','/vision/runs/m/clarify/mentions');
 await mkdir('.local/screenshots/SP-E8-16S',{recursive:true}); await page.screenshot({path:'.local/screenshots/SP-E8-16S/report.png',fullPage:true});
 await page.goto(url+'&missing=1');
 await expect(page.locator('#overview').getByText('Недостаточно данных для расчёта')).toHaveCount(4);
});
test('сетевая ошибка не показывает устаревший отчёт',async({page})=>{
 await page.goto(url+'&error=1');
 await expect(page.getByRole('alert')).toContainText('Не удалось загрузить отчёт');
 await expect(page.locator('#overview')).toHaveCount(0);
});
