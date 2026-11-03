import {expect,test} from '@playwright/test';
test.skip(process.env.RUN_E811_FIXTURE!=='1','Requires isolated E8-11 fixture server.');
test.use({viewport:{width:1440,height:900},launchOptions:process.env.E811_CHROME?{executablePath:process.env.E811_CHROME}:{}});
const base='http://127.0.0.1:5291/tests/Browser/E8-11/fixture.html';
test('карточка площадки содержит проверенный источник',async({page})=>{
 await page.goto(base+'?sources');await page.getByText('example.test',{exact:true}).first().click();
 await expect(page.getByText('Модель: gemini')).toBeVisible();await expect(page.getByRole('link',{name:'Открыть источник'})).toHaveAttribute('href','https://example.test/');
 await page.screenshot({path:'.local/screenshots/e8-11/sources.png',fullPage:true});
});
test('история конкурентов ведёт к отчёту',async({page})=>{
 await page.goto(base);await page.getByLabel('Первая компания').selectOption('Альфа');await page.getByLabel('Вторая компания').selectOption('Бета');await expect(page.getByRole('columnheader',{name:'Альфа'})).toBeVisible();await expect(page.getByRole('heading',{name:'История замеров'})).toBeVisible();
 await expect(page.getByRole('link',{name:/Отчёт от/})).toHaveAttribute('href','/vision/report?report=r1');
 await page.screenshot({path:'.local/screenshots/e8-11/competitors.png',fullPage:true});
});

test('сайт, факты и рекомендации показывают данные снимка',async({page})=>{
 await page.goto(base+'?report');await expect(page.getByText(/Сайт из анкеты на момент замера: https/)).toBeVisible();
 await expect(page.getByText('Проверенный факт')).toBeVisible();await expect(page.getByText('Обновить сведения')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/e8-11/report.png',fullPage:true});
});
