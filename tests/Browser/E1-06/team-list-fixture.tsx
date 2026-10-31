import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Shell/Team/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const params = new URLSearchParams(location.search);
const theme =
  ['neutral', 'pyro', 'confectionery'].find((value) => value === params.get('theme')) ?? 'neutral';
const brand = { id: '01J00000000000000000000004', name: 'Пиротехника — Северные огни', theme };
const shared = {
  auth: {
    user: { id: '01J00000000000000000000002', name: 'Иван Петров', email: 'ivan@example.test' },
  },
  organization: { id: '01J00000000000000000000001', name: 'Союз Пир' },
  brand,
  brands: [brand],
  modules: { seller: true, vision: true },
  can: {
    'org.read': true,
    'team.read': true,
    'team.manage': true,
    'billing.read': true,
    'integrations.read': true,
    'profile.own': true,
    'seller.dialogs.read': true,
    'vision.report.read': true,
  },
  unread: null,
  request_id: 'fixture',
  theme,
  translations: {},
  errors: {},
  ziggy: {
    routes: {
      company: { uri: 'company', methods: ['GET'] },
      team: { uri: 'team', methods: ['GET'] },
      roles: { uri: 'team/roles', methods: ['GET'] },
    },
    url: location.origin,
    port: null,
    defaults: {},
    location: { host: location.host, pathname: '/team', search: '' },
  },
  canViewRoles: true,
  members: {
    current_page: 1,
    last_page: 2,
    total: 27,
    data: [
      {
        id: '1',
        name: 'Иван Петров',
        email: 'ivan@example.test',
        role: 'owner',
        role_label: 'Владелец',
        status: 'active',
        brands: [brand],
      },
      {
        id: '2',
        name: 'Анна Иванова',
        email: 'anna@example.test',
        role: 'manager',
        role_label: 'Менеджер',
        status: 'disabled',
        brands: [],
      },
    ],
  },
};
const script = document.createElement('script');
script.type = 'application/json';
script.dataset.page = 'app';
script.textContent = JSON.stringify({
  component: 'Shell/Team/Index',
  props: shared,
  url: '/team',
  version: 'fixture',
  clearHistory: false,
  encryptHistory: false,
});
document.body.append(script);
void createInertiaApp({
  resolve: () => Index,
  setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
