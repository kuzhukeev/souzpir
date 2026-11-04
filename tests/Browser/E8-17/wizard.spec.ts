import { expect, test } from '@playwright/test';
test.skip(process.env.RUN_E817_FIXTURE !== '1', 'Requires isolated fixture server.');
test.use({viewport:{width:1440,height:900},launchOptions:process.env.E817_CHROME ? {executablePath:process.env.E817_CHROME}:{}});
const url='http://127.0.0.1:35988/tests/Browser/E8-17/fixture.html';
test('confirmed mention can be marked for the brand profile', async ({page})=>{
 await page.goto(url+'?step=mentions');
 await expect(page.locator('mark')).toHaveText('Союз-Пир СПб');
 await page.getByRole('radio',{name:'Да, это мы'}).check();
 await expect(page.getByRole('checkbox',{name:'Добавить в профиль как вариант названия'})).toBeEnabled();
 await page.getByRole('checkbox',{name:'Добавить в профиль как вариант названия'}).check();
 await page.screenshot({path:'.local/screenshots/sp-e8-17/mentions.png',fullPage:true});
});
test('facts and empty mentions explain their state', async ({page})=>{
 await page.goto(url+'?step=facts');
 await expect(page.getByText('Неверные факты сохраняются для отчёта; отображение в отчёте появится позже.')).toBeVisible();
 await page.getByRole('radio',{name:'Неверно'}).check();
 await expect(page.getByLabel('Как правильно')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/sp-e8-17/facts.png',fullPage:true});
 await page.goto(url+'?step=mentions&empty');
 await expect(page.getByText('Нет упоминаний для уточнения.')).toBeVisible();
});
