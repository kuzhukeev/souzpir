import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
let vite: ViteDevServer; let url: string;
test.beforeAll(async () => { vite = await createServer({ configFile: 'tests/Browser/E8-16/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await vite.listen(); url = new URL('/tests/Browser/E8-16/fixture.html', vite.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await vite?.close(); });
test.use({viewport:{width:1440,height:900},launchOptions:process.env.E816_CHROME ? {executablePath:process.env.E816_CHROME}:{}});
test('launch parameters show slot calculation', async ({page})=>{
 await page.goto(url); await page.getByRole('button',{name:'Параметры замера'}).click();
 await expect(page.getByRole('dialog').getByText('Оценка времени недоступна: нет завершённого замера.')).toBeVisible();
 await expect(page.getByRole('dialog').getByText('Вопросы: 2 · Модели: 1 · Повторы: 1 · Всего слотов: 2')).toBeVisible();
 await page.getByRole('dialog').getByRole('group',{name:'Число прогонов'}).getByRole('button',{name:'2'}).click();
 await expect(page.getByRole('dialog').getByText('Оценка времени недоступна: нет завершённого замера.')).toBeVisible();
 await expect(page.getByRole('dialog').getByText('Вопросы: 2 · Модели: 1 · Повторы: 2 · Всего слотов: 4')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/sp-e8-16/launch.png',fullPage:true});
});
test('answers can be filtered and opened', async ({page})=>{
 await page.goto(url); await expect(page.getByText('Статусы моделей')).toBeVisible();
 await page.getByLabel('Месяц').fill('2026-10');
 await expect(page.getByText('Сохранённый ответ бренда')).toBeVisible();
 await page.getByRole('combobox',{name:/Статус/}).selectOption('ok');
 await expect(page.getByText('Ошибка: Провайдер отклонил запрос')).toHaveCount(0);
 await page.getByRole('button',{name:'Открыть ответ'}).click();
 await expect(page.getByRole('dialog').getByText('Сохранённый ответ бренда')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/sp-e8-16/answer.png',fullPage:true});
});

