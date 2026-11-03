import {expect,test} from '@playwright/test';
test.skip(process.env.RUN_E812C_FIXTURE!=='1','Requires E8-12C fixture server');
test.use({viewport:{width:1440,height:900},launchOptions:process.env.E812C_CHROME?{executablePath:process.env.E812C_CHROME}:{}});
test('оценка и дата показывают изменение того же сайта',async({page})=>{await page.goto('http://127.0.0.1:5293/tests/Browser/E8-12C/fixture.html');await expect(page.getByText('Изменение: +20 п.п.')).toBeVisible();await expect(page.getByText(/Дата проверки:/)).toBeVisible();await page.screenshot({path:'.local/screenshots/e8-12c/overview.png'});});
test('разные сайты не сравниваются',async({page})=>{await page.goto('http://127.0.0.1:5293/tests/Browser/E8-12C/fixture.html?different');await expect(page.getByText('Изменение пока недоступно')).toBeVisible();});
