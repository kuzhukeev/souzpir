import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Settings/Limits/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const params = new URLSearchParams(location.search);
const empty = params.has('empty');
const rows = empty ? [] : [
  { key: 'seller.dialogs_month', label: 'Диалоги за месяц', value: 800, status: 'ready', limit: '1000', configured: true },
  { key: 'seller.messages_month', label: 'Сообщения клиентов и продавца', value: 2400, status: 'ready', limit: '5000', configured: true },
  { key: 'seller.channels_active', label: 'Активные каналы', value: 2, status: 'ready', limit: null, configured: false },
  { key: 'org.members_active', label: 'Участники компании', value: 3, status: 'ready', limit: null, configured: false },
];
const props = { rows, budget: null, period: { from: '2026-11-01', to: '2026-12-01', timezone: 'UTC' }, daysRemaining: 12, canViewPlans: !params.has('restricted'),
  brand: { id: 'brand', name: 'Союз Пир', theme: 'neutral' }, brands: [], organization: { id: 'org', name: 'Компания' }, auth: { user: { id: 'user', name: 'Иван Петров', email: 'ivan@example.test' } }, modules: { seller: true, vision: false }, can: {}, unread: null, translations: {}, ziggy: { routes: {notifications:{uri:'seller/settings/notifications',methods:['GET']}} }, errors: {} };
const page = { component: 'Fixture', props, url: '/seller/settings/limits', version: null, clearHistory: false, encryptHistory: false };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify(page); document.body.appendChild(script);
void createInertiaApp({ resolve: () => Index, setup: ({ el, App, props: pageProps }) => createRoot(el).render(<App {...pageProps} />) });
