import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Greetings from '../../../resources/js/pages/Seller/Settings/Greetings/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const readOnly = location.search.includes('readonly');
const page = { component: 'Fixture', props: {
  greetings: { widget: 'Здравствуйте, {имя}! Раздел: {страница}', widget_catalog: 'Рады видеть в каталоге, {имя}.', after_hours: 'Вернёмся завтра, {имя}.' }, canWrite: !readOnly,
  theme: 'neutral', brand: { id: 'b', name: 'Пример бренда', theme: 'neutral' }, brands: [], organization: { id: 'o', name: 'Компания' },
  auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } }, modules: { seller: true, vision: false }, can: {}, unread: null, translations: {}, ziggy: { routes: {} }, errors: {},
}, url: location.pathname, version: null, clearHistory: false, encryptHistory: false };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify(page); document.body.appendChild(script);
void createInertiaApp({ resolve: () => Greetings, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
