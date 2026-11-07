import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Settings from '../../../resources/js/pages/Seller/Channels/Api/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const page = { component: 'Fixture', props: { mapping: { name: 'name', email: 'email', city: 'city' }, logs: [{ id: '1', endpoint: 'leads', source: 'site', status: 'success', created_at: '2026-11-03T12:00:00Z' }, { id: '2', endpoint: 'turn', source: 'crm', status: 'error', error: 'HTTP 422', created_at: '2026-11-03T12:05:00Z' }], status: null, theme: 'neutral', brand: { id: 'b', name: 'Тестовый бренд', theme: 'neutral' }, brands: [], organization: { id: 'o', name: 'Компания' }, auth: { user: { id: 'u', name: 'Иван', email: 'a@example.test' } }, modules: { seller: true, vision: false }, can: {}, unread: null, translations: {}, ziggy: { routes: {} }, errors: {} }, url: location.pathname, version: null, clearHistory: false, encryptHistory: false };
const script = document.createElement('script');
script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify(page); document.body.appendChild(script);
void createInertiaApp({ resolve: () => Settings, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
