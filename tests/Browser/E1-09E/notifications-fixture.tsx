import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Notifications from '../../../resources/js/pages/Shell/Journal/Notifications';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const rows = {
  data: [
    { id: 'n1', created_at: '2026-11-01T11:00:00Z', channel: 'telegram', status: 'pending', recipient: 'Иван Петров', title: 'Контакт получен', attempts: 0 },
    { id: 'n2', created_at: '2026-11-01T12:30:00Z', channel: 'email', status: 'failed', recipient: 'Анна Смирнова', title: 'Замер готов', attempts: 1 },
    { id: 'n3', created_at: '2026-11-01T14:02:00Z', channel: 'cabinet', status: 'sent', recipient: 'Иван Петров', title: 'Передача менеджеру', attempts: 1 },
  ],
  current_page: 1,
  last_page: 1,
  total: 3,
};
const page = {
  component: 'Fixture',
  props: {
    rows,
    filters: {},
    theme: new URLSearchParams(location.search).get('theme') ?? 'neutral',
    brand: { id: 'b', name: 'Пример бренда', theme: 'neutral' },
    brands: [],
    organization: { id: 'o', name: 'Компания' },
    auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } },
    modules: { seller: true, vision: false },
    can: {},
    unread: null,
    translations: {},
    ziggy: { routes: {} },
    errors: {},
  },
  url: location.pathname,
  version: null,
  clearHistory: false,
  encryptHistory: false,
};
const script = document.createElement('script');
script.type = 'application/json';
script.dataset.page = 'app';
script.textContent = JSON.stringify(page);
document.body.appendChild(script);
void createInertiaApp({ resolve: () => Notifications, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
