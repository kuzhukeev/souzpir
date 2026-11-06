import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
let server: ViteDevServer;
let fixture: string;
test.beforeAll(async () => { server = await createServer({configFile:'tests/Browser/E7-06D/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}}); await server.listen(); fixture = new URL('/tests/Browser/E7-06D/fixture.html',server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test.use({launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test('выбор канала использует коды и показывает пустое состояние', async ({page}) => {
  await page.setViewportSize({width:1440,height:900}); await page.goto(fixture);
  const select = page.getByLabel('Канал', {exact:true});
  await expect(select).toHaveValue('');
  for (const [value,label] of [['telegram','Telegram'],['vk','ВКонтакте'],['whatsapp','WhatsApp'],['api','API'],['widget','Сайт']]) await expect(select.locator(`option[value="${value}"]`)).toHaveText(label);
  await select.selectOption('widget'); await expect(select).toHaveValue('widget');
  await expect(page.getByText('Клиентов пока нет или они не найдены.',{exact:false})).toBeVisible();
  await page.screenshot({path:'.local/screenshots/SP-E7-06D/customers.png',fullPage:true});
});
test('карточка факта не показывает неподтверждённый пример', async ({page}) => {
  await page.setViewportSize({width:1440,height:900}); await page.goto(`${fixture}?fact`);
  await expect(page.getByRole('heading',{name:'Карточка факта'})).toBeVisible();
  await expect(page.getByRole('heading',{name:'Как бот это скажет'})).toHaveCount(0);
  await expect(page.locator('textarea')).toHaveValue('Доставка по России');
  await page.screenshot({path:'.local/screenshots/SP-E7-06D/fact.png',fullPage:true});
});


test('фильтр показывает загрузку и ошибку сети', async ({page}) => {
  await page.goto(fixture);
  await page.route('**/seller/customers?**', async route => { await new Promise(resolve => setTimeout(resolve, 400)); await route.abort(); });
  await page.getByRole('button',{name:'Применить'}).click();
  await expect(page.getByText('Загружаем клиентов…')).toBeVisible();
  await expect(page.getByRole('alert')).toContainText('Не удалось связаться с сервером.');
});
