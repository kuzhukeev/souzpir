import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Channels/Planned/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const params = new URLSearchParams(location.search);
const section = params.get('section') ?? 'planned';
const theme = params.get('theme') ?? 'neutral';
const page = {
  component: 'Fixture',
  props: {
    section,
    planned: { items: [], brandId: 'b', canManage: false, interestAvailable: false, reminders: { enabled: false, available: false } },
    theme,
    brand: { id: 'b', name: 'Пример бренда', theme },
    brands: [],
    organization: { id: 'o', name: 'Компания' },
    auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } },
    modules: { vision: false, seller: true },
    can: {},
    unread: null,
    translations: {},
    ziggy: { routes: {} },
    errors: {},
  },
  url: section === 'planned' ? '/seller/integrations/planned' : '/seller/channels/vk',
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
