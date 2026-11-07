import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let fixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server=await createServer({configFile:'tests/Browser/E8-15E/vite.config.ts',server:{host:'127.0.0.1',port:0}}); await server.listen(); fixture=new URL('/tests/Browser/E8-15E/pages-fixture.html',server.resolvedUrls!.local[0]).href; });
test.afterAll(async()=>{await server?.close();});
const question={id:'q1',text:'Где найти качественные услуги рядом?',intent:'choose',status:'proposed',selected:true,origin:'manual',can_delete:true};
const data={candidates:[question],active_set:null,history:[],limits:{questions:'20',models:'3'},models:[{id:'google/gemini-2.5-flash',provider:'gemini',status:'active'}],generation:{status:'blocked'},can_edit:true,has_profile:true};
test('расход соответствует числу вопросов и моделей',async({page})=>{
 await page.route('**/vision/questions/data',route=>route.fulfill({json:data}));
 await page.goto(`${fixture}?screen=approve`);
 await page.getByLabel('google/gemini-2.5-flash').check();
 const cost=page.getByText('Будет списано 1 слот в неделю (1 прогон).');
 await expect(cost).toHaveCount(0);
 await page.getByRole('checkbox',{name:'Включить автоматическое расписание'}).check();
 await expect(cost).toBeVisible();
 await page.getByLabel('Частота').selectOption('biweekly');await expect(page.getByText('За один запланированный прогон: 1 слот.')).toBeVisible();
 await page.getByLabel('Частота').selectOption('monthly');await expect(page.getByText('За один запланированный прогон: 1 слот.')).toBeVisible();
 await mkdir('.local/screenshots/SP-E8-15E',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E8-15E/weekly.png',fullPage:true});
 await page.getByRole('checkbox',{name:'Включить автоматическое расписание'}).uncheck();await expect(cost).toHaveCount(0);
});
test('без права запуска расписание недоступно',async({page})=>{
 await page.route('**/vision/questions/data',route=>route.fulfill({json:data}));await page.goto(`${fixture}?screen=approve&readonly=1`);
 await expect(page.getByRole('checkbox',{name:'Включить автоматическое расписание'})).toBeDisabled();
});
