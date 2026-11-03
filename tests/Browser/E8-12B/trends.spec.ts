import { expect, test } from '@playwright/test';
import { spawn, type ChildProcess } from 'node:child_process';
let server: ChildProcess;
const fixture='http://127.0.0.1:5294/tests/Browser/E8-12B/fixture.html';
test.beforeAll(async()=>{
  server=spawn(process.execPath,['node_modules/vite/bin/vite.js','--config','tests/Browser/E8-12B/vite.config.ts'],{stdio:'ignore'});
  for(let attempt=0;attempt<60;attempt++){try{if((await fetch(fixture)).ok)return;}catch{/* Wait for Vite. */}if(server.exitCode!==null)throw new Error('Vite exited');await new Promise(resolve=>setTimeout(resolve,500));}
  throw new Error('Vite did not start');
});
test.afterAll(()=>server?.kill());
test.use({launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test('Открыть переходит к отчёту выбранного замера',async({page})=>{
  await page.setViewportSize({width:1440,height:900});
  await page.goto(fixture);
  const link=page.getByRole('row').filter({hasText:'10.10.2026'}).getByRole('link',{name:'Открыть'});
  await expect(link).toHaveAttribute('href','/vision/report?report=report-2');
  await page.screenshot({path:'.local/screenshots/e8-12b/trends.png',fullPage:true});
  await page.route('**/vision/report?report=report-2',route=>route.fulfill({status:200,headers:{'Content-Type':'application/json','X-Inertia':'true'},body:JSON.stringify({component:'Target',props:{errors:{}},url:'/vision/report?report=report-2',version:null,clearHistory:false,encryptHistory:false})}));
  await link.click();
  await expect(page).toHaveURL(/\/vision\/report\?report=report-2$/);
  await expect(page.getByRole('heading',{name:'Выбранный отчёт'})).toBeVisible();
});
