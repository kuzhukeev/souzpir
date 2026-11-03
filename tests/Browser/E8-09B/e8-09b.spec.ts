import {expect,test} from '@playwright/test';
test.skip(process.env.RUN_E809B_FIXTURE!=='1','Requires E8-09B fixture server');
test.use({viewport:{width:1440,height:900},launchOptions:process.env.E809B_CHROME?{executablePath:process.env.E809B_CHROME}:{}});
const base='http://127.0.0.1:5294/tests/Browser/E8-09B/fixture.html';
test('старт показывает сохранённый шаг',async({page})=>{await page.goto(base);await expect(page.getByText('Анкета: шаг 4 из 8')).toBeVisible();await page.screenshot({path:'.local/screenshots/e8-09b/start.png'});});
test('анкета показывает время сохранения',async({page})=>{await page.goto(base+'?screen=quiz');await expect(page.getByRole('status').filter({hasText:'Черновик сохранён'})).toBeVisible();await page.screenshot({path:'.local/screenshots/e8-09b/quiz.png'});});
test('история доступна в модальном сравнении',async({page})=>{await page.goto(base+'?screen=profile');await page.getByRole('button',{name:'Сравнить с предыдущей версией'}).click();const dialog=page.getByRole('dialog');await expect(dialog.getByText('Старое имя')).toBeVisible();await expect(dialog.getByText('Новое имя')).toBeVisible();await page.screenshot({path:'.local/screenshots/e8-09b/profile.png'});await dialog.getByRole('button',{name:'Закрыть'}).click();await expect(dialog).toHaveCount(0);});
