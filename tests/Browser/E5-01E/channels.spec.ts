import {expect,test} from '@playwright/test';
test.use({launchOptions:{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}});
test('ссылки каналов и настройки Telegram',async ({page})=>{
 await page.setViewportSize({width:1440,height:900});
 await page.goto('http://127.0.0.1:5251/tests/Browser/E5-01E/fixture.html');
 await expect(page.locator('a[href="/seller/channels/email"]')).toBeVisible();
 await expect(page.locator('a[href="/integrations/api"]')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/sp-e5-01e/channels.png',fullPage:true});
 await page.goto('http://127.0.0.1:5251/tests/Browser/E5-01E/fixture.html?settings');
 await expect(page.getByText('Ожидаем подтверждение чата.')).toBeVisible();
 await expect(page.getByText('Статус: Не установлен')).toBeVisible();
 await expect(page.getByRole('radio',{name:'Подсказки'})).toBeVisible();
 await page.getByRole('radio',{name:'Подсказки'}).check();
 await expect(page.getByRole('radio',{name:'Подсказки'})).toBeChecked();
 await page.screenshot({path:'.local/screenshots/sp-e5-01e/telegram.png',fullPage:true});
});
