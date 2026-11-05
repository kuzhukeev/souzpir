import {expect,test} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import {mkdir} from 'node:fs/promises';
const php=process.env.E819F_PHP || 'php';
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test('публичный снимок содержит графики и подтверждённую историю',async({page})=>{const html=execFileSync(php,['tests/Browser/E8-19F/render.php'],{encoding:'utf8'});await page.setContent(html);await expect(page.getByLabel('Динамика: Видимость')).toBeVisible();await expect(page.getByRole('heading',{name:'История изменений'})).toBeVisible();await expect(page.getByRole('cell',{name:'04.10.2026'})).toBeVisible();await mkdir('.local/screenshots/SP-E8-19F',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E8-19F/public.png',fullPage:true});});
