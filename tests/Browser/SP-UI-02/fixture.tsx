import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Shell/Billing/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const page = {
  component: 'Fixture',
  props: {
    tab: 'usage',
    currentPlan: null,
    limits: [],
    usage: [],
    plans: [],
    canManage: true,
    requestEnabled: false,
    period: { from: '2026-11-01', to: '2026-11-30' },
    theme: 'neutral',
    brand: { id: 'b', name: 'Пример бренда', theme: 'neutral' },
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
  url: '/billing/usage',
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
