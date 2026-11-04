import { expect, test } from '@playwright/test';
import axe from 'axe-core';
const base = 'http://127.0.0.1:5258/tests/Browser/E8-03/pages-fixture.html';
test.use({
  launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH },
});
const questions = [
  { id: '1', text: 'Где заказать товары для праздника?', intent: 'choose' },
  { id: '2', text: 'Какие есть условия доставки?', intent: 'terms' },
];
const active = {
  id: 'set2',
  version: 2,
  questions,
  size: 2,
  checksum: 'a'.repeat(64),
  providers: { gemini: 'google/gemini' },
  frequency: null,
  active: true,
  approved_by: 'u',
  approver_name: 'Иван Петров',
  created_at: '2026-10-31 12:10:39+00',
};
const data = {
  candidates: questions.map((q) => ({
    ...q,
    status: 'active',
    selected: true,
    origin: 'manual',
    can_delete: true,
  })),
  active_set: active,
  history: [
    active,
    { ...active, id: 'set1', version: 1, questions: [questions[0]], size: 1, active: false },
  ],
  limits: { questions: null, models: null },
  models: [
    { id: 'google/gemini', provider: 'gemini', status: 'active' },
    { id: 'yandexgpt', provider: 'yandexgpt', status: 'planned' },
  ],
  generation: { status: 'blocked' },
  can_edit: true,
  has_profile: true,
};
for (const screen of ['index', 'approve', 'active', 'history'])
  for (const theme of ['neutral', 'confectionery', 'pyro'])
    for (const width of [1440, 1024, 390])
      test(`${screen} ${theme} ${width}`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        await page.route('**/*', (r) => {
          const url = new URL(r.request().url());
          if (url.origin !== 'http://127.0.0.1:5258') return r.abort();
          if (url.pathname === '/vision/questions/data') return r.fulfill({ json: data });
          return r.continue();
        });
        await page.goto(`${base}?screen=${screen}&theme=${theme}`);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await expect(page.getByText('Загружаем вопросы…')).toHaveCount(0);
        expect(
          await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        ).toBeTruthy();
        await page.addScriptTag({ content: axe.source });
        const violations = await page.evaluate(
          async () =>
            (
              await (window as unknown as { axe: typeof axe }).axe.run(document, {
                runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] },
              })
            ).violations,
        );
        expect(violations).toEqual([]);
        await page.screenshot({
          path: `.local/screenshots/e8-03/${screen}-${theme}-${width}.png`,
          fullPage: true,
        });
      });
test('empty history and accessible duplicate warning', async ({ page }) => {
  await page.route('**/*', (r) => {
    const url = new URL(r.request().url());
    if (url.origin !== 'http://127.0.0.1:5258') return r.abort();
    if (url.pathname === '/vision/questions/data')
      return r.fulfill({ json: { ...data, history: [] } });
    return r.continue();
  });
  await page.goto(`${base}?screen=history`);
  await expect(page.getByText('Истории наборов пока нет')).toBeVisible();
  await page.goto(`${base}?screen=index`);
  await page.getByRole('button', { name: 'Добавить свой' }).click();
  await page.getByLabel('Текст вопроса').fill(questions[0].text);
  await expect(page.getByText('Похожий вопрос уже есть')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Добавить вопрос', exact: true })).toBeEnabled();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog')).toHaveCount(0);
});

test('history diff compares normalized text and intent as a multiset', async ({ page }) => {
  const prev = [
    { id: 'old1', text: 'Как выбрать товар?', intent: 'choose' },
    { id: 'old2', text: 'Как выбрать товар?', intent: 'choose' },
    { id: 'old3', text: 'Где заказать?', intent: 'choose' },
  ];
  const current = [
    { id: 'new1', text: '  как выбрать  товар? ', intent: 'choose' },
    { id: 'new2', text: 'Где заказать?', intent: 'price' },
    { id: 'new3', text: 'Новый вопрос?', intent: 'choose' },
  ];
  await page.route('**/*', (r) => {
    const url = new URL(r.request().url());
    if (url.origin !== 'http://127.0.0.1:5258') return r.abort();
    if (url.pathname === '/vision/questions/data')
      return r.fulfill({
        json: {
          ...data,
          history: [
            { ...active, questions: current, size: 3 },
            { ...active, id: 'before', version: 1, questions: prev, size: 3, active: false },
          ],
        },
      });
    return r.continue();
  });
  await page.goto(`${base}?screen=history`);
  await expect(page.getByRole('cell', { name: '+2 / −2' })).toBeVisible();
  await page.getByRole('button', { name: 'Сравнить с предыдущим' }).click();
  await expect(page.getByText('Новый вопрос?')).toBeVisible();
  await expect(page.getByText('Как выбрать товар?', { exact: true })).toBeVisible();
});
