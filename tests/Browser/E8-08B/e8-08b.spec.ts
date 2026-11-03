import { expect, test } from '@playwright/test';
test.skip(process.env.RUN_E808B_FIXTURE !== '1', 'Requires isolated E8-08B fixture server.');
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.E808B_CHROME ? { executablePath: process.env.E808B_CHROME } : {} });
const base='http://127.0.0.1:5289/tests/Browser/E8-08B/fixture.html';
const question={id:'q1',text:'Где заказать товары?',intent:'choose',status:'active',selected:true,origin:'manual',can_delete:true};
const set={id:'s1',version:1,questions:[question],size:1,checksum:'a'.repeat(64),providers:{gemini:'google/gemini'},frequency:null,active:true,approved_by:'u',approver_name:'Иван Петров',created_at:'2026-10-31 12:10:39+00',measurements:[{id:'measurement-1',status:'pending',created_at:'2026-10-31'}]};
const data={candidates:[question],active_set:set,history:[set],limits:{questions:'30',models:'4'},models:[{id:'google/gemini',provider:'gemini',status:'active'}],generation:{status:'blocked'},can_edit:true,has_profile:true};
test.beforeEach(async({page})=>{ await page.route('**/vision/questions/data', route=>route.fulfill({json:data})); });
test('предложенные показывают слот, источник и меню',async({page})=>{
 await page.goto(base+'?screen=index');
 await expect(page.getByText(/слотов по тарифу/)).toBeVisible();
 await expect(page.getByText('Добавлен вручную')).toBeVisible();
 await page.getByLabel('Действия с вопросом').click();
 await expect(page.getByRole('button',{name:'Изменить'})).toBeVisible();
 await page.screenshot({path:'.local/screenshots/e8-08b/index.png',fullPage:true});
});
test('утверждение и история показывают данные замера',async({page})=>{
 await page.goto(base+'?screen=approve');
 await expect(page.getByText('Настройки первого замера')).toBeVisible();
 await expect(page.getByRole('button',{name:'Утвердить и настроить запуск'})).toBeVisible();
 await page.screenshot({path:'.local/screenshots/e8-08b/approve.png',fullPage:true});
 await page.goto(base+'?screen=history');
 await expect(page.getByText('Автор: Иван Петров')).toBeVisible();
 await expect(page.getByText('Замеры: measurement-1')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/e8-08b/history.png',fullPage:true});
});

test('пустой раздел и кнопка выбора остаются доступны', async ({ page }) => {
 await page.route('**/vision/questions/data', route => route.fulfill({json:{...data,candidates:[]}}));
 await page.goto(base+'?screen=index');
 await expect(page.getByText('Пока нет вопросов в этом разделе.')).toBeVisible();
});
test('кнопка добавления в набор видима без открытия меню', async ({ page }) => {
 await page.route('**/vision/questions/data', route => route.fulfill({json:{...data,candidates:[{...question,selected:false}]}}));
 await page.goto(base+'?screen=index');
 await expect(page.getByRole('button',{name:'В набор',exact:true})).toBeVisible();
});
