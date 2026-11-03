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

test('настройки показывают шесть событий и сохраняют выбранный канал',async({page})=>{
 await page.goto('http://127.0.0.1:5292/tests/Browser/E8-12/fixture.html?settings');
 await page.getByRole('button',{name:'Уведомления',exact:true}).click();
 await expect(page.getByRole('row')).toHaveCount(7);await page.getByLabel('Замер готов: email').check();
 await page.route('**/vision/settings/notifications',route=>route.fulfill({status:422,contentType:'application/json',body:'{}'}));
 const request=page.waitForRequest('**/vision/settings/notifications');await page.getByRole('button',{name:'Сохранить уведомления'}).click();
 expect((await request).postDataJSON().changes).toHaveLength(18);
 await page.screenshot({path:'.local/screenshots/e8-12/settings.png',fullPage:true});
});
test('эффект показывает сравнение с оговоркой',async({page})=>{
 await page.goto('http://127.0.0.1:5292/tests/Browser/E8-12/fixture.html?effect');
 await expect(page.getByText(/не доказывает причинную связь/)).toBeVisible();await expect(page.getByText(/изменение \+15/)).toBeVisible();
 await page.screenshot({path:'.local/screenshots/e8-12/effect.png',fullPage:true});
});
