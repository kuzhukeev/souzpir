import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Vision/Site/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const params = new URLSearchParams(location.search);
const theme = params.get('theme') ?? 'neutral';
const section = params.get('section') ?? 'history';
const action = { id: 1, title: 'Текущее действие', status: 'open' };
const page = {
  component: 'Fixture',
  props: {
    section,
    audit: null,
    actions: [action],
    history: params.has('filled')
      ? [{ id: 2, title: 'Завершённое действие', status: 'awaiting_measurement' }]
      : [],
    canManage: false,
    members: [],
    theme,
    brand: { id: 'b', name: 'Пример бренда', theme },
    brands: [],
    organization: { id: 'o', name: 'Компания' },
    auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } },
    modules: { vision: true, seller: false },
    can: {},
    unread: null,
    translations: {},
    ziggy: { routes: {} },
    errors: {},
  },
  url: section === 'history' ? '/vision/actions/history' : '/vision/actions',
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
  resolve: () => Index,
  setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
