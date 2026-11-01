import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Modules from '../../../resources/js/pages/Shell/Organizations/Modules';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const params = new URLSearchParams(location.search);
const theme = params.get('theme') ?? 'neutral';
const paused = params.has('paused');
const usage = { value: 0, unit: 'раз', status: 'ready' };
const page = {
  component: 'Fixture',
  props: {
    targetBrand: { id: 'b', name: 'Пример бренда', status: paused ? 'archived' : 'active' },
    items: {
      vision: { usage, quota: { configured: true, value: 10 }, enabled: true, configured_enabled: true, allowed: true, limit: { configured: true, value: 1 } },
      seller: { usage, quota: { configured: false, value: null }, enabled: false, configured_enabled: false, allowed: false, limit: { configured: true, value: 0 } },
    },
    theme,
    brand: { id: 'b', name: 'Пример бренда', theme },
    brands: [],
    organization: { id: 'o', name: 'Компания' },
    auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } },
    modules: { vision: true, seller: true },
    can: {},
    unread: null,
    translations: {},
    ziggy: { routes: {} },
    errors: {},
  },
  url: '/brands/b/modules',
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
  resolve: () => Modules,
  setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
