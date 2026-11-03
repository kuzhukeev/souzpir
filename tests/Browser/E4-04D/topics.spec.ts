import {expect,test} from '@playwright/test';
test.use({launchOptions:{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}});
test('процент ответов и удаление факта из темы',async ({page})=>{
 await page.setViewportSize({width:1440,height:900});
 await page.goto('http://127.0.0.1:5252/tests/Browser/E4-04D/fixture.html');
 await expect(page.getByText('50 %')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/sp-e4-04d/topics.png',fullPage:true});
 await page.getByRole('button',{name:'Редактировать'}).click();
 await expect(page.getByText('Черновик',{exact:true})).toBeVisible();
 await expect(page.getByRole('button',{name:'Убрать факт: Условия доставки'})).toBeVisible();
 await page.screenshot({path:'.local/screenshots/sp-e4-04d/topic.png',fullPage:true});
 await page.getByRole('button',{name:'Убрать факт: Условия доставки'}).click();
 await expect(page.getByRole('button',{name:'Убрать факт: Условия доставки'})).toHaveCount(0);
});
