import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Merge from '../../../resources/js/pages/Vision/Competitors/Merge';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const entities = ['Альфа', 'ООО Альфа', 'Гамма'].map((name) => ({
  name,
  is_brand: false,
  aliases: name === 'Альфа' ? ['Альфа старая'] : [],
  sources: ['example.test'],
}));
const props = {
  auth: { user: { id: 'u', name: 'Иван', email: 'ivan@example.test' } },
  organization: { id: 'o', name: 'Компания' },
  brand: { id: 'b', name: 'Бренд' },
  brands: [],
  modules: { seller: false, vision: true },
  can: { 'profile.own': true },
  theme: 'neutral',
  translations: {},
  ziggy: { routes: {} },
  errors: {},
  available: !new URLSearchParams(location.search).has('empty'),
  canEdit: !new URLSearchParams(location.search).has('viewer'),
  status: null,
  entities,
  distinctPairs: [],
};
const script = document.createElement('script');
script.type = 'application/json';
script.dataset.page = 'app';
script.textContent = JSON.stringify({
  component: 'Fixture',
  props,
  url: '/vision/competitors/merge',
  version: 'fixture',
  clearHistory: false,
  encryptHistory: false,
});
document.body.append(script);
void createInertiaApp({
  resolve: () => Merge,
  setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
