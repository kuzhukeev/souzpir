import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let fixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-21Q/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); fixture = new URL('/tests/Browser/E8-21Q/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('показывает измеренное время проверки и этапы по макету',async({page})=>{await page.goto(fixture);await expect(page.getByText('Время проверки: 8 с.')).toBeVisible();await expect(page.getByText('Осталось около 1 мин.')).toBeVisible();await expect(page.getByText('Доступ ИИ-ботов · готово')).toBeVisible();await expect(page.getByText('Страницы сайта · выполняется')).toBeVisible();await expect(page.getByText('Структурированные данные · ожидает')).toBeVisible();await expect(page.getByText('Итоговая оценка · ожидает')).toBeVisible();await page.getByRole('button',{name:'Завершить проверку'}).click();await expect(page.getByText('Время проверки: 37 с.')).toBeVisible();await mkdir('.local/screenshots/SP-E8-21Q',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E8-21Q/completed.png',fullPage:true});});
