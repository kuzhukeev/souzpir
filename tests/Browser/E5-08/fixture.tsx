import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Duplicates from '../../../resources/js/pages/Seller/Customers/ImportExport/Duplicates';
import ImportExport from '../../../resources/js/pages/Seller/Customers/ImportExport/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const importScreen = location.search.includes('import');
const first = { id: '01K00000000000000000000001', name: 'Анна Петрова', phone: '+7 900 123-45-67', email: 'anna@example.test', city: 'Тверь' };
const second = { id: '01K00000000000000000000002', name: 'Анна П.', phone: '+7 900 123-45-67', email: null, city: 'Тверь' };
const page = {
  component: 'Fixture',
  props: {
    ...(importScreen ? { preview: { id: '01K00000000000000000000003', filename: 'clients.csv', headers: ['Имя', 'Почта', 'Город'], rows: [['Мария', 'maria@example.test', 'Казань']], count: 1 } } : { pairs: [{ first, second, reason: 'Телефон' }], contacts: [first, second] }),
    status: null, theme: 'neutral', brand: { id: 'b', name: 'Пример бренда', theme: 'neutral' }, brands: [],
    organization: { id: 'o', name: 'Компания' }, auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } },
    modules: { seller: true, vision: false }, can: {}, unread: null, translations: {}, ziggy: { routes: {} }, errors: {},
  },
  url: location.pathname + location.search, version: null, clearHistory: false, encryptHistory: false,
};
const script = document.createElement('script');
script.type = 'application/json';
script.dataset.page = 'app';
script.textContent = JSON.stringify(page);
document.body.appendChild(script);
void createInertiaApp({ resolve: () => importScreen ? ImportExport : Duplicates, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
