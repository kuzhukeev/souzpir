import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Shell/HomeEntry/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const params = new URLSearchParams(location.search);
const theme = params.get('theme') ?? 'neutral';
const page = {
  component: 'Fixture',
  props: {
    homeLinks: params.has('empty') ? [] : [
      { id: 'company', label: 'Компания', description: 'Настройки компании', href: '/company' },
      { id: 'team', label: 'Команда', description: 'Участники и доступ', href: '/team' },
      { id: 'journal', label: 'Журнал', description: 'История действий', href: '/journal' },
      { id: 'profile', label: 'Профиль', description: 'Личные настройки', href: '/profile' },
    ],
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
  url: '/dashboard',
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
