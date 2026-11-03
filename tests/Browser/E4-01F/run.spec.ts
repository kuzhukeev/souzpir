import { expect, test } from '@playwright/test';
test.use({launchOptions:{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}});
test('результат и подтверждение видны на экране',async ({page})=>{
 await page.setViewportSize({width:1440,height:900});
 await page.goto('http://127.0.0.1:5250/tests/Browser/E4-01F/fixture.html');
 await expect(page.getByText('Тестовый прогон: пройдено 1, провалено 1.')).toBeVisible();
 await page.getByRole('button',{name:'Опубликовать',exact:true}).first().click();
 await expect(page.getByLabel('Подтверждаю публикацию при проваленных сценариях')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/sp-e4-01f/releases.png',fullPage:true});
});
