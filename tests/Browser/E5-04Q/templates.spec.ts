import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer; let url: string;
test.use({ viewport: {width: 1440, height: 900}, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {} });
test.beforeAll(async () => { server = await createServer({configFile: 'tests/Browser/E5-04Q/vite.config.ts', cacheDir: '.local/runtime/vite-e504q', server: {host: '127.0.0.1', port: 0, strictPort: false}}); await server.listen(); url = new URL('/tests/Browser/E5-04Q/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('редактирование шаблонов и проверка пустых полей', async ({page}) => {
  await page.goto(url);
  await page.getByRole('button', {name: 'Изменить шаблоны'}).click();
  await page.getByRole('button', {name: 'Добавить шаблон'}).click();
  await page.getByRole('button', {name: 'Сохранить шаблоны'}).click();
  await expect(page.getByRole('alert')).toContainText('Заполните название и текст');
  await page.getByLabel('Название').last().fill('Уточнение');
  await page.getByLabel('Текст', {exact: true}).last().fill('Уточните, пожалуйста.');
  await mkdir('.local/screenshots/SP-E5-04Q', {recursive: true});
  await page.screenshot({path: '.local/screenshots/SP-E5-04Q/templates.png', fullPage: true});
});
