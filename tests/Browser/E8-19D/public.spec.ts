import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
const php = process.env.E819D_PHP || 'php';
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test('публичный снимок показывает только сохранённые оценку и действия',async({page})=>{const html=execFileSync(php,['tests/Browser/E8-19D/render.php'],{encoding:'utf8'});await page.setContent(html);await expect(page.getByLabel('Оценка сайта на момент публикации')).toContainText('35–60');await expect(page.getByText('Правила доступа роботов')).toBeVisible();await expect(page.getByText('Уточнить описание товаров',{exact:true})).toBeVisible();await mkdir('.local/screenshots/sp-e8-19d',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e8-19d/public.png',fullPage:true});await page.setViewportSize({width:390,height:844});const cards=page.locator('.grid > .card');const first=await cards.nth(0).boundingBox();const second=await cards.nth(1).boundingBox();expect(first?.y).toBe(second?.y);});
