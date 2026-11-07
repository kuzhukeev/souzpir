import {expect,test,type Page} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
let server:ViteDevServer;let base:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.E508D_CHROME?{executablePath:process.env.E508D_CHROME}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E5-08D/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();base=server.resolvedUrls!.local[0];});
test.afterAll(async()=>{await server?.close();});
const open=(page:Page,file:string)=>page.goto(new URL(`/tests/Browser/E5-08D/${file}`,base).href);
const axe=async(page:Page)=>{await page.addScriptTag({path:'node_modules/axe-core/axe.min.js'});return page.evaluate(async()=>{const a=(window as unknown as {axe:{run:(d:Document,o:object)=>Promise<{violations:{id:string;impact:string}[]}>}}).axe;return (await a.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa']}})).violations.filter(v=>v.impact==='critical'||v.impact==='serious').map(v=>v.id);});};
test('шаблон и подготовка архива за период',async({page})=>{
 await open(page,'fixture.html');
 await expect(page.getByRole('link',{name:'Скачать шаблон CSV'})).toHaveAttribute('href','/seller/customer-io/template');
 await expect(page.getByLabel('Стадии')).toBeEnabled();
 await page.getByLabel('Формат').selectOption('xlsx');
 await page.getByLabel('С',{exact:true}).fill('2026-01-01');await page.getByLabel('По',{exact:true}).fill('2026-01-31');
 await page.screenshot({path:'.local/screenshots/e5-08d/export-import.png',fullPage:true});
 await page.route('**/seller/customer-io/export?**', route=>route.fulfill({status:200,contentType:'text/html',body:'Готово'}));
 const request=page.waitForRequest('**/seller/customer-io/export?**');await page.getByRole('button',{name:'Подготовить'}).click();
 const query=new URL((await request).url()).searchParams;expect(query.get('from')).toBe('2026-01-01');expect(query.get('to')).toBe('2026-01-31');expect(query.get('format')).toBe('xlsx');
});
test('зона перетаскивания CSV и кнопка выбора файла',async({page})=>{
 await open(page,'fixture.html');
 await expect(page.getByText('Перетащите CSV-файл сюда или')).toBeVisible();
 const [chooser]=await Promise.all([page.waitForEvent('filechooser'),page.getByRole('button',{name:'Выбрать файл'}).click()]);
 await chooser.setFiles({name:'clients.csv',mimeType:'text/csv',buffer:Buffer.from('Имя;Телефон\nАнна;123\n')});
 await expect(page.getByText('clients.csv')).toBeVisible();
});
test('фильтр причины совпадения на странице дублей',async({page})=>{
 await open(page,'duplicates.html');
 await expect(page.getByText('Совпадения по телефону, почте или имени и городу')).toBeVisible();
 await expect(page.getByText('Совпадает: Телефон')).toBeVisible();
 await expect(page.getByText('Совпадает: Почта')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/e5-08d/duplicates.png',fullPage:true});
 await page.getByLabel('Причина совпадения').selectOption('Телефон');
 await expect(page.getByText('Совпадает: Телефон')).toBeVisible();
 await expect(page.getByText('Совпадает: Почта')).toHaveCount(0);
});
test('390, клавиатура и axe на импорте/экспорте и дублях',async({page})=>{
 for(const file of ['fixture.html','duplicates.html']){
  for(const width of [1440,390]){
   await page.setViewportSize({width,height:900});await open(page,file);
   await expect(page.getByRole('heading',{level:1})).toBeVisible();
   expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1)).toBe(true);
   expect(await axe(page)).toEqual([]);
  }
 }
 await page.setViewportSize({width:1440,height:900});await open(page,'duplicates.html');
 await page.getByLabel('Причина совпадения').focus();await page.keyboard.press('ArrowDown');
 await page.getByLabel('Причина совпадения').selectOption('Почта');
 await expect(page.getByText('Совпадает: Телефон')).toHaveCount(0);
 await open(page,'fixture.html');
 await page.getByRole('button',{name:'Выбрать файл'}).focus();
 const [chooser]=await Promise.all([page.waitForEvent('filechooser'),page.keyboard.press('Enter')]);
 expect(chooser).toBeTruthy();
});
