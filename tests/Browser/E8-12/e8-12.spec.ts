import {expect,test} from '@playwright/test';
test.skip(process.env.RUN_E812_FIXTURE!=='1','Requires isolated E8-12 fixture server.');test.use({viewport:{width:1440,height:900},launchOptions:process.env.E812_CHROME?{executablePath:process.env.E812_CHROME}:{}});
test('графики моделей, намерений и компаний ограничиваются датами',async({page})=>{
 await page.goto('http://127.0.0.1:5292/tests/Browser/E8-12/fixture.html');
 await page.getByRole('button',{name:'По нейросетям'}).click();await expect(page.getByRole('img',{name:'gemini: динамика'})).toBeVisible();
 await page.screenshot({path:'.local/screenshots/e8-12/models.png',fullPage:true});
 await page.getByLabel('С',{exact:true}).fill('2026-10-05');await expect(page.getByRole('columnheader',{name:'01.10.2026'})).toHaveCount(0);
 await page.getByRole('button',{name:'По намерениям'}).click();await expect(page.getByRole('img',{name:'Выбор и сравнение: динамика'})).toBeVisible();
 await page.screenshot({path:'.local/screenshots/e8-12/intents.png',fullPage:true});
 await page.getByRole('button',{name:'Доля голоса'}).click();await expect(page.getByRole('img',{name:'Альфа: динамика'})).toBeVisible();
 await page.screenshot({path:'.local/screenshots/e8-12/share.png',fullPage:true});
 await page.getByLabel('По',{exact:true}).fill('2026-10-02');await expect(page.getByRole('alert')).toContainText('Дата начала позже');
});
