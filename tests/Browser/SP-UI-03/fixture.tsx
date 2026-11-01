import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Help from '../../../resources/js/pages/Shell/Support/Help';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const theme = new URLSearchParams(location.search).get('theme') ?? 'neutral';
const page = {
  component: 'Fixture',
  props: {
    help: {
      configured: true,
      sections: [
        { id: 'start', title: 'Начало работы', description: 'Как начать работу', url: 'http://127.0.0.1:5262/#guide' },
        { id: 'later', title: 'Скоро', description: 'Материалы готовятся', url: null },
      ],
      articles: [{ id: 'article', title: 'Первое руководство', url: 'http://127.0.0.1:5262/#article', section_id: 'start' }],
      knowledge_base_url: 'http://127.0.0.1:5262/#knowledge',
    },
    theme,
    brand: { id: 'b', name: 'Пример бренда', theme },
    brands: [],
    organization: { id: 'o', name: 'Компания' },
    auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } },
    modules: { vision: false, seller: false },
    can: {},
    unread: null,
    translations: {},
    ziggy: { routes: {} },
    errors: {},
  },
  url: '/help',
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
  resolve: () => Help,
  setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
