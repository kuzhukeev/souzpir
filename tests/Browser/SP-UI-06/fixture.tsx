import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Settings/Limits/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const params = new URLSearchParams(location.search);
const theme = params.get('theme') ?? 'neutral';
const available = !params.has('unavailable');
const page = {
  component: 'Fixture',
  props: {
    rows: [{ key: 'conversations', label: 'Диалоги', value: 12, status: 'ready', limit: '100', configured: true }],
    budget: null,
    period: { from: '2026-11-01', to: '2026-11-30', timezone: 'UTC' },
    theme,
    brand: { id: 'b', name: 'Пример бренда', theme },
    brands: [],
    organization: { id: 'o', name: 'Компания' },
    auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } },
    modules: { vision: false, seller: true },
    can: {},
    unread: null,
    translations: {},
    ziggy: { routes: available ? { notifications: { uri: 'seller/settings/notifications', methods: ['GET'] } } : {} },
    errors: {},
  },
  url: '/seller/settings/limits',
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
