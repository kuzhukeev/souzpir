import { expect, test } from '@playwright/test';
test.skip(process.env.RUN_E816_FIXTURE !== '1', 'Requires isolated fixture server.');
test.use({viewport:{width:1440,height:900},launchOptions:process.env.E816_CHROME ? {executablePath:process.env.E816_CHROME}:{}});
const url='http://127.0.0.1:35987/tests/Browser/E8-16/fixture.html';
test('launch parameters show slot calculation', async ({page})=>{
 await page.goto(url); await page.getByRole('button',{name:'Параметры замера'}).click();
 await expect(page.getByRole('dialog').getByText('Вопросы: 2 · Модели: 1 · Повторы: 1 · Всего слотов: 2')).toBeVisible();
 await page.getByRole('dialog').getByLabel('Повторов').fill('2');
 await expect(page.getByRole('dialog').getByText('Вопросы: 2 · Модели: 1 · Повторы: 2 · Всего слотов: 4')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/sp-e8-16/launch.png',fullPage:true});
});
test('answers can be filtered and opened', async ({page})=>{
 await page.goto(url); await expect(page.getByText('Прогресс по моделям')).toBeVisible();
 await page.getByLabel('Месяц').fill('2026-10');
 await expect(page.getByText('Сохранённый ответ бренда')).toBeVisible();
 await page.getByRole('combobox',{name:/Статус/}).selectOption('ok');
 await expect(page.getByText('Ошибка: Провайдер отклонил запрос')).toHaveCount(0);
 await page.getByRole('button',{name:'Открыть ответ'}).click();
 await expect(page.getByRole('dialog').getByText('Сохранённый ответ бренда')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/sp-e8-16/answer.png',fullPage:true});
});

