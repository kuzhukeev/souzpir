import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Notifications from '../../../resources/js/pages/Shell/Journal/Notifications';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const page = {
  component: 'Fixture',
  props: {
    rows: {
      data: [
        { id: 'n1', created_at: '2026-11-01T12:00:00Z', channel: 'email', status: 'failed', recipient: 'one@example.test', title: 'Первое уведомление' },
        { id: 'n2', created_at: '2026-11-01T12:01:00Z', channel: 'telegram', status: 'failed', recipient: 'two@example.test', title: 'Второе уведомление' },
      ],
      current_page: 1,
      last_page: 1,
      total: 2,
    },
    filters: {},
    brand: { id: 'b', name: 'Пример бренда', theme: 'neutral' },
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
  url: '/journal/notifications',
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
  resolve: () => Notifications,
  setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
