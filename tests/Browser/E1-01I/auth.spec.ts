import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.setTimeout(60000);
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E1-01I/vite.config.ts',cacheDir:'.local/runtime/vite-e101i',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E1-01I/fixture.html',server.resolvedUrls!.local[0]).href;await mkdir('.local/screenshots/SP-E1-01I',{recursive:true});});
test.afterAll(async()=>{await server?.close();});
test('сброс пароля и блокировка входа',async({page})=>{
 await page.goto(url+'?success');await expect(page.getByRole('heading',{name:'Пароль изменён'})).toBeVisible();await expect(page.getByRole('link',{name:'Войти в кабинет'})).toHaveAttribute('href','/login');await page.screenshot({path:'.local/screenshots/SP-E1-01I/success.png',fullPage:true});
 await page.goto(url+'?locked');await expect(page.getByRole('alert')).toContainText('Слишком много попыток');await expect(page.getByRole('button',{name:'Войти'})).toBeDisabled();await page.screenshot({path:'.local/screenshots/SP-E1-01I/login.png',fullPage:true});
});
test('регистрация сохраняет маску и согласие',async({page})=>{
 await page.goto(url+'?screen=register');await page.getByLabel('Телефон · необязательно').fill('9123456789');await expect(page.getByLabel('Телефон · необязательно')).toHaveValue('+7 (912) 345-67-89');await expect(page.getByText(/через OpenRouter/)).toBeVisible();await page.screenshot({path:'.local/screenshots/SP-E1-01I/register.png',fullPage:true});
});
test('состояние приглашения предлагает выйти из другого аккаунта',async({page})=>{
 await page.goto(url+'?screen=invitation&state=email_mismatch');await expect(page.getByRole('heading',{name:'Почта не совпадает'})).toBeVisible();await expect(page.getByRole('button',{name:'Выйти из аккаунта'})).toBeVisible();await page.screenshot({path:'.local/screenshots/SP-E1-01I/invitation.png',fullPage:true});
});

test('подвал входа содержит ссылки Условия и Конфиденциальность',async({page})=>{
 await page.goto(url);await expect(page.getByRole('link',{name:'Условия'})).toHaveAttribute('href','/help');await expect(page.getByRole('link',{name:'Конфиденциальность'})).toHaveAttribute('href','/help');
});
test('ошибки входа и регистрации показаны общим сообщением',async({page})=>{
 await page.goto(url); await page.route('**/login', route => route.fulfill({status:200,headers:{'x-inertia':'true','content-type':'application/json'},body:JSON.stringify({component:'Fixture',props:{errors:{email:'Неверная почта или пароль'},status:null,lockoutEmail:'',lockoutSeconds:0,theme:'neutral'},url:'/login',version:'fixture',clearHistory:false,encryptHistory:false})}));
 await page.getByLabel('Рабочая почта').fill('user@example.test');await page.getByLabel('Пароль',{exact:true}).fill('wrong');await page.getByRole('button',{name:'Войти',exact:true}).click();await expect(page.getByRole('alert')).toContainText('Неверная почта или пароль');
 await page.unrouteAll();await page.goto(url+'?screen=register');await page.route('**/register', route => route.fulfill({status:200,headers:{'x-inertia':'true','content-type':'application/json'},body:JSON.stringify({component:'Fixture',props:{errors:{email:'Неверная почта'},theme:'neutral'},url:'/register',version:'fixture',clearHistory:false,encryptHistory:false})}));
 await page.getByLabel('Имя',{exact:true}).fill('Иван');await page.getByLabel('Рабочая почта').fill('user@example.test');await page.getByLabel('Пароль',{exact:true}).fill('long-password-123');await page.getByLabel('Повторите пароль').fill('long-password-123');await page.getByLabel('Код создания кабинета').fill('test-code');await page.getByLabel(/Я принимаю условия/).check();await page.getByLabel(/через OpenRouter/).check();await page.getByRole('button',{name:'Зарегистрироваться'}).click();await expect(page.getByRole('alert').filter({hasText:'Проверьте поля'})).toBeVisible();
});

test('адаптивные состояния и клавиатура в трёх темах',async({page})=>{
 for(const [width,theme] of [[1440,'neutral'],[1024,'confectionery'],[390,'pyro']] as const){
  await page.setViewportSize({width,height:900});await page.goto(url+'?screen=invitation&state=email_mismatch&theme='+theme);
  await expect(page.locator('html')).toHaveAttribute('data-theme',theme);
  await page.getByRole('button',{name:'Выйти из аккаунта'}).focus();await expect(page.getByRole('button',{name:'Выйти из аккаунта'})).toBeFocused();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth+1)).toBe(true);
 }
});
