import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Settings from '../../../resources/js/pages/Shell/Brands/Settings';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const theme = new URLSearchParams(location.search).get('theme') ?? 'neutral';
const limit = { value: null, configured: false, unit: '' };
const moduleEntry = {
  enabled: false,
  configured_enabled: false,
  allowed: true,
  limit,
  quota: limit,
  usage: { value: null, unit: '', status: 'unavailable' },
};
const page = {
  component: 'Fixture',
  props: {
    targetBrand: {
      id: 'b', name: 'Пример бренда', website: null, industry: null, market: null,
      language: 'ru', timezone: 'Europe/Moscow', theme, status: 'active', logo_url: null,
      settings: {}, website_check: null,
    },
    domains: [],
    moduleItems: { seller: moduleEntry, vision: moduleEntry },
    permissions: { manage: true },
    networkChecksEnabled: false,
    theme,
    brand: { id: 'b', name: 'Пример бренда', theme },
    brands: [],
    organization: { id: 'o', name: 'Компания' },
    auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } },
    modules: { seller: true, vision: true },
    can: {},
    unread: null,
    translations: {},
    ziggy: { routes: {} },
    errors: {},
  },
  url: location.pathname + location.search,
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
  resolve: () => Settings,
  setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
