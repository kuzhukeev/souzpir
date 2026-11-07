import { expect, test } from '@playwright/test';
test.skip(process.env.RUN_E501D_FIXTURE !== '1', 'Requires isolated fixture server.');
test.use({ viewport:{width:1440,height:900}, launchOptions:process.env.E501D_CHROME ? {executablePath:process.env.E501D_CHROME}:{} });
const url='http://127.0.0.1:35986/tests/Browser/E5-01D/fixture.html';
test('channels show modes, last activity and available or planned channels',async({page})=>{
 await page.goto(url); await expect(page.getByText('Режимы каналов')).toBeVisible();
 for(const name of ['Сайт','WhatsApp','ВКонтакте','Почта','Входящие формы и API']) await expect(page.getByRole('heading',{name,exact:true})).toBeVisible();
 await expect(page.getByText('Последняя активность:',{exact:false})).toBeVisible();
 await page.screenshot({path:'.local/screenshots/sp-e5-01d/channels.png',fullPage:true});
});
test('Telegram shows registration and honest preview',async({page})=>{
 await page.goto(url+'?settings'); await expect(page.getByRole('button',{name:'Зарегистрировать вебхук'})).toBeVisible();
 await expect(page.getByRole('heading',{name:'Как выглядят подсказки'})).toBeVisible();
 await expect(page.getByText('Пример оформления; действия пока недоступны.')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/sp-e5-01d/telegram.png',fullPage:true});
});
