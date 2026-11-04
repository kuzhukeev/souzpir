import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

test.setTimeout(60000);
let server: ViteDevServer;
let fixture: string;
test.beforeAll(async () => {
  server = await createServer({configFile: 'tests/Browser/E5-04G/vite.config.ts', server: {host: '127.0.0.1', port: 0}});
  await server.listen();
  fixture = new URL('/tests/Browser/E5-04J/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } });
test('редактор карточки, шаблон и вложение доступны в диалоге', async ({page}) => {
  await page.setViewportSize({width:1440,height:900});
  await page.goto(fixture);
  const card = page.getByRole('complementary', {name:'Карточка клиента'});
  await card.getByText('Редактировать карточку').click();
  await card.getByLabel('Город', {exact:true}).fill('Казань');
  await expect(card.getByRole('button',{name:'Сохранить карточку'})).toBeVisible();
  await page.getByLabel('Шаблон фразы').selectOption({label:'Приветствие'});
  await expect(page.getByRole('textbox',{name:'Сообщение',exact:true})).toHaveValue('Здравствуйте, Анна! Чем могу помочь?');
  await expect(page.getByLabel('Вложение (до 10 МБ)')).toBeVisible();
  await page.screenshot({path:'.local/screenshots/SP-E5-04J/dialog.png',fullPage:true});
  await page.setViewportSize({width:390,height:844});
  await expect(page.getByRole('textbox',{name:'Сообщение',exact:true})).toBeVisible();
});
