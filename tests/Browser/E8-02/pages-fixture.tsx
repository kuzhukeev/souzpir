import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Start from '../../../resources/js/pages/Vision/Profile/Start';
import Quiz from '../../../resources/js/pages/Vision/Profile/Quiz';
import Show from '../../../resources/js/pages/Vision/Profile/Show';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const params = new URLSearchParams(location.search),
  screen = params.get('screen') ?? 'start',
  theme = params.get('theme') ?? 'neutral';
const answers = {
  step1: { brand_name: 'Пример бренда', site: 'https://example.com' },
  step2: { aliases: ['example.com', 'Пример бренда'] },
  step3: {
    category: 'Пиротехника',
    audience: 'both',
    offering: 'goods',
    buyer_phrase: 'купить салют',
    category_forms: { nominative: 'салюты', genitive_plural: 'салютов' },
  },
  step4: { competitors: [] },
  step5: { facts: ['Доставка по России'] },
  step6: { presence: [] },
  step7: { questions: [] },
};
const profile = {
  id: 'fixture-profile',
  quiz_id: 'fixture-quiz',
  version: 2,
  checksum: 'fixture',
  answers,
};
const shared = {
  theme,
  brand: { id: 'b', name: 'Пример бренда', website: 'https://example.com', theme },
  brands: [],
  organization: { id: 'o', name: 'Компания' },
  auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } },
  modules: { vision: true, seller: false },
  can: { 'vision.quiz.write': true },
  unread: null,
  translations: {},
  ziggy: { routes: {dashboard:{uri:"dashboard",methods:["GET"]}} },
  errors: {},
};
const props = {
  ...shared,
  ...(screen === 'start'
    ? { current: null, profile_id: null, can_edit: true, secure_context: true }
    : screen === 'quiz'
      ? {
          quiz: { id: 'q', version: 1, revision: 1, status: 'draft', current_step: 1, answers },
          step: 1,
          limits: { competitors: 10, facts: 10, questions: 15 },
          hints: { status: 'unavailable', items: [] },
        }
      : {
          profile,
          previous_profile: {
            ...profile,
            id: 'previous',
            version: 1,
            answers: {
              ...answers,
              step1: { brand_name: 'Прошлое название', site: 'https://example.com' },
            },
          },
          history: [
            {
              id: 'fixture-profile',
              version: 2,
              source: 'user',
              created_at: '2026-10-31 12:10:39+00',
            },
          ],
          is_latest: true,
          limits: { competitors: 10, facts: 10, questions: 15 },
        }),
};
const page = {
  component: 'Fixture',
  props,
  url: screen === 'start' ? '/vision' : screen === 'quiz' ? '/vision/quiz/1' : '/vision/profile',
  version: null,
  clearHistory: false,
  encryptHistory: false,
};
const script = document.createElement('script');
script.type = 'application/json';
script.dataset.page = 'app';
script.textContent = JSON.stringify(page);
document.body.appendChild(script);
void createInertiaApp({
  resolve: () => (screen === 'start' ? Start : screen === 'quiz' ? Quiz : Show),
  setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
