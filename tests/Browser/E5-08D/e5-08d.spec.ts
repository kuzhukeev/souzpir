import {expect,test} from '@playwright/test';
test.skip(process.env.RUN_E508D_FIXTURE!=='1','Requires isolated E5-08D fixture server.');
test.use({viewport:{width:1440,height:900},launchOptions:process.env.E508D_CHROME?{executablePath:process.env.E508D_CHROME}:{}});
test('шаблон и подготовка архива за период',async({page})=>{
 await page.goto('http://127.0.0.1:5290/tests/Browser/E5-08D/fixture.html');
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
 await page.goto('http://127.0.0.1:5290/tests/Browser/E5-08D/fixture.html');
 await expect(page.getByText('Перетащите CSV-файл сюда или')).toBeVisible();
 const [chooser]=await Promise.all([page.waitForEvent('filechooser'),page.getByRole('button',{name:'Выбрать файл'}).click()]);
 await chooser.setFiles({name:'clients.csv',mimeType:'text/csv',buffer:Buffer.from('Имя;Телефон\nАнна;123\n')});
 await expect(page.getByText('clients.csv')).toBeVisible();
});
test('фильтр причины совпадения на странице дублей',async({page})=>{
 await page.goto('http://127.0.0.1:5290/tests/Browser/E5-08D/duplicates.html');
 await expect(page.getByText('Совпадения по телефону, почте или имени и городу')).toBeVisible();
 await expect(page.getByText('Совпадает: Телефон')).toBeVisible();
 await expect(page.getByText('Совпадает: Почта')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/e5-08d/duplicates.png',fullPage:true});
 await page.getByLabel('Причина совпадения').selectOption('Телефон');
 await expect(page.getByText('Совпадает: Телефон')).toBeVisible();
 await expect(page.getByText('Совпадает: Почта')).toHaveCount(0);
});
