import { expect, test } from '@playwright/test';
test.skip(process.env.RUN_E107B_FIXTURE !== '1', 'Requires isolated E1-07B fixture server.');
test.use({viewport:{width:1440,height:900},launchOptions:process.env.E107B_CHROME ? {executablePath:process.env.E107B_CHROME}: {}});
const fixture='http://127.0.0.1:5288/tests/Browser/E1-07B/fixture.html';
test('использование показывает бренд, график, таблицу и CSV',async({page})=>{
 await page.goto(fixture);
 await expect(page.getByRole('heading',{name:'Использование по дням'})).toBeVisible();
 await expect(page.getByRole('table').getByText('Диалоги продавца')).toBeVisible();
 await expect(page.getByRole('link',{name:'Экспорт CSV'})).toHaveAttribute('href','/billing/usage/export?from=2026-10-01&to=2026-10-31');
 await page.screenshot({path:'.local/screenshots/e1-07b/usage.png',fullPage:true});
});
test('пустой период и ошибка дат',async({page})=>{
 await page.goto(fixture+'?empty');
 await expect(page.getByText('Нет данных за выбранный период')).toBeVisible();
 await page.getByLabel('С',{exact:true}).fill('2026-11-01');
 await page.getByRole('button',{name:'Показать'}).click();
 await expect(page.getByText('Дата начала не может быть позже даты окончания.')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/e1-07b/empty.png',fullPage:true});
});
