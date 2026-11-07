import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E5-02D/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E5-02D/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('установка, внешний вид и поведение доступны без переписки',async({page})=>{
 for(const tab of ['install','look','behavior']){
  await page.setViewportSize({width:1440,height:900});await page.goto(`${url}?tab=${tab}`);
  await expect(page.getByText('Переписка работает в тестовом режиме',{exact:false})).toBeVisible();
  if(tab==='install'){
   await page.getByText('WordPress',{exact:true}).click();await expect(page.getByText('В настройках темы или плагина',{exact:false})).toBeVisible();
   await page.getByLabel('Использовать домен бренда: https://example.test').check();await expect(page.getByLabel('Адреса сайтов')).toHaveValue('https://example.test');
   await expect(page.getByRole('button',{name:'Проверить установку'})).toBeVisible();
  }
  if(tab==='look'){
   await page.getByLabel('Отступ сбоку, px').fill('12');await page.getByLabel('Форма').selectOption('round');await page.getByRole('button',{name:'Мобильный'}).click();
   await expect(page.getByRole('button',{name:'Мобильный'})).toHaveAttribute('aria-pressed','true');await expect(page.getByRole('link',{name:'Изменить приветствие →'})).toHaveAttribute('href','/seller/settings/greetings');
  }
  if(tab==='behavior'){
   await page.getByLabel('Пн',{exact:true}).check();await page.getByLabel('Начало',{exact:true}).fill('09:00');await page.getByLabel('Маски URL, по одной в строке').fill('/privacy');await page.getByLabel('Лимит сообщений').fill('20');
   await page.getByText('Обязательно',{exact:true}).click();await expect(page.getByRole('radio',{name:'Обязательно'})).toBeChecked();await page.getByLabel('Текст согласия').fill('Согласен');await expect(page.getByLabel('Текст согласия')).toHaveAttribute('maxlength','300');
  }
  await mkdir('.local/screenshots/SP-E5-02D',{recursive:true});await page.screenshot({path:`.local/screenshots/SP-E5-02D/${tab}.png`,fullPage:true});
 }
 for(const theme of ['neutral','pyro','confectionery'])for(const width of [1024,390]){await page.setViewportSize({width,height:900});await page.goto(`${url}?tab=behavior&theme=${theme}`);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBeTruthy();}
});
