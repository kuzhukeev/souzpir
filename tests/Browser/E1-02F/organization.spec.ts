import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E1-02F/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E1-02F/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('главная, меню и ошибка перехода',async({page})=>{await page.goto(`${url}?dashboard=1`);await expect(page.getByRole('link',{name:/Выбор компании/})).toHaveCount(2);await expect(page.getByRole('link',{name:/Текущая компания и доступные бренды/})).toHaveAttribute('href','/context/organization');await mkdir('.local/screenshots/sp-e1-02f',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e1-02f/dashboard.png',fullPage:true});await page.goto(url);await expect(page.getByRole('link',{name:'Выбор компании'})).toBeVisible();await page.route('**/dashboard',async route=>{await new Promise(resolve=>setTimeout(resolve,700));await route.abort();});await page.getByRole('link',{name:/Союз Пир Владелец/}).click();await expect(page.getByRole('status')).toContainText('Открываем кабинет');await expect(page.getByRole('alert')).toContainText('Не удалось открыть кабинет');await page.screenshot({path:'.local/screenshots/sp-e1-02f/error.png',fullPage:true});});

test('нет ссылки без права профиля',async({page})=>{await page.goto(`${url}?dashboard=1&forbid=1`);await expect(page.getByRole('link',{name:/Выбор компании/})).toHaveCount(0);});

test('обновление показывает загрузку и ошибку',async({page})=>{
  await page.goto(url);
  let release!:()=>void;
  const waitForAssertion=new Promise<void>(resolve=>{release=resolve;});
  await page.route('**/context/organization*',async route=>{await waitForAssertion;await route.abort();});
  await page.getByRole('button',{name:'Обновить данные'}).click();
  try { await expect(page.getByRole('status')).toContainText('Загрузка данных компании'); }
  finally { release(); }
  await expect(page.getByRole('alert')).toContainText('Не удалось загрузить данные компании');
  await expect(page.getByRole('link',{name:/Союз Пир Владелец/})).toBeVisible();
});
