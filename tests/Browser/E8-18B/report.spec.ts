import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
test.setTimeout(60000);let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-18B/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E8-18B/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('данные компаний и рекомендации только по сохранённым связям',async({page})=>{
 const rev='a'.repeat(64);const sections={site:null,competitors:[{name:'Наш бренд',answers:3,is_brand:true,models:['gemini','openai']},{name:'Другая компания',answers:1,is_brand:false,models:[]}],gaps:[{intent:'choose',text:'Как выбрать?',recommendations:[{title:'Обновить описание',why:'Данные снимка'}]},{intent:'choose',text:'Где купить?',recommendations:[]}],facts:[{text:'Ошибка в адресе',reason:'Устарел',provider:'gemini'}],sources:[],actions:[],changes:null};
 const data={revision:rev,confirmed:[],reports:[],report:{id:'r',measurement_id:'m',visibility:25,visibility_kind:'full',coverage:100,share_of_voice:null,position:null,verified_errors:1,providers:[],questions:2,evidence_status:'available',sections}};
 await page.route('**/vision/report/data**',route=>route.fulfill({json:data}));await page.route('**/vision/runs/revision',route=>route.fulfill({json:{revision:rev}}));await page.goto(url);
 await page.getByText('Показать модели').click();await expect(page.getByText('gemini, openai')).toBeVisible();await expect(page.getByRole('link',{name:'Объединить дубли →'})).toHaveAttribute('href','/vision/competitors');
 await page.getByText('Выбор и сравнение',{exact:true}).click();await page.getByText('Что сделать',{exact:true}).click();await expect(page.getByText('Обновить описание')).toBeVisible();await expect(page.getByText('Рекомендация для этого вопроса пока не сохранена.')).toBeVisible();await expect(page.getByText('Подтверждённые ошибки: 1.',{exact:false})).toBeVisible();
 await page.screenshot({path:'.local/screenshots/sp-e8-18b/report.png',fullPage:true});
});
