import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Shell/Dashboard/Index';
import Attention from '../../../resources/js/pages/Shell/Dashboard/Attention';
import Notifications from '../../../resources/js/pages/Shell/Notifications/Index';

const params = new URLSearchParams(location.search);
const screen = params.get('screen') ?? 'home';
const empty = params.has('empty');

const daily = Object.fromEntries(Array.from({ length: 7 }, (_, day) => [`2026-10-${22 + day}`, empty ? 0 : [26, 31, 34, 39, 27, 34, 42][day]]));
const dailyContacts = Object.fromEntries(Array.from({ length: 7 }, (_, day) => [`2026-10-${22 + day}`, empty ? 0 : [9, 10, 11, 14, 10, 12, 18][day]]));
const seller = {
  days: 7, total: empty ? 0 : 248, contact: empty ? 0 : 84, handoff: empty ? 0 : 31, quality: empty ? null : 4.6,
  delta: { total: 12, contact: 18, handoff: 24, quality: 0.3 }, daily, daily_contacts: dailyContacts,
  topics: {},
  issue_details: empty ? [] : [
    { type: 'Цены и скидки', share: 32, delta: 6, href: '/seller/quality' },
    { type: 'Наличие на складе', share: 18, delta: -4, href: '/seller/quality' },
    { type: 'Доставка в регионы', share: 15, delta: 3, href: '/seller/quality' },
  ],
  attention: empty ? [] : [
    { kind: 'manager', title: 'Диалоги ждут менеджера', count: 3, href: '/seller/dialogs?mode=manager', severity: 'high' },
    { kind: 'knowledge', title: 'Неизвестные вопросы', count: 4, href: '/seller/knowledge', severity: 'normal' },
  ],
  channels: empty ? [] : [
    { name: 'Сайт', type: 'widget', status: 'active', mode: 'bot', dialogs: 132, contacts: 46, conversion: 35 },
    { name: 'Telegram', type: 'telegram', status: 'active', mode: 'bot', dialogs: 78, contacts: 26, conversion: 33 },
    { name: 'WhatsApp', type: 'whatsapp', status: 'active', mode: 'bot', dialogs: 38, contacts: 12, conversion: 32 },
  ],
};
const items = empty ? [] : [
  { id: '1', kind: 'event', group: 'seller', title: 'Диалог ждёт менеджера', read: false, created_at: new Date().toISOString() },
  { id: '2', kind: 'event', group: 'vision', title: 'Замер видимости готов', read: false, created_at: new Date().toISOString() },
  { id: '3', kind: 'event', group: 'shell', title: 'Приглашение принято', read: true, created_at: new Date(Date.now() - 86_400_000).toISOString() },
];
const homeProps = {
  homeLinks: [{ id: 'company', label: 'Компания и бренды', description: 'Данные компании и доступные бренды', href: '/context/organization' }],
  seller: empty ? null : seller, vision: empty ? null : { id: 'v1', visibility: 64, share: null, measurement_at: '2026-10-24', mentions: 32, questions: 50 },
  nextMeasurement: '2026-10-31', canVision: true, visionError: false, usage: {}, usageError: false, items, unread: empty ? 0 : 2,
};
const attentionProps = { seller: empty ? null : seller };
const notificationsProps = { items, unread: empty ? 0 : 2 };
const byScreen = { home: { component: Index, props: homeProps, url: '/dashboard' }, attention: { component: Attention, props: attentionProps, url: '/attention' },
  notifications: { component: Notifications, props: notificationsProps, url: '/notification-center' } } as const;
const page = byScreen[screen as keyof typeof byScreen] ?? byScreen.home;
const shared = {
  auth: { user: { id: 'user', name: 'Иван Петров', email: 'ivan@example.test' } },
  organization: { id: 'org', name: 'Пиротехника' }, brand: { id: 'brand-a', name: 'Пиротехника', theme: 'neutral' as const },
  brands: [{ id: 'brand-a', name: 'Пиротехника' }], modules: { seller: true, vision: true },
  can: { 'profile.own': true, 'seller.dialogs.read': true, 'vision.report.read': true }, theme: 'neutral',
  ziggy: { routes: { dashboard: { uri: 'dashboard', methods: ['GET'] }, 'dashboard.attention': { uri: 'attention', methods: ['GET'] },
    'dashboard.notification-center': { uri: 'notification-center', methods: ['GET'] }, 'dashboard.notifications': { uri: 'notifications', methods: ['GET'] } } },
  translations: {}, errors: {}, unread: empty ? 0 : 2, brandAppearance: null, request_id: 'fixture',
};
const script = document.createElement('script');
script.type = 'application/json'; script.dataset.page = 'app';
script.textContent = JSON.stringify({ component: 'Fixture', props: { ...shared, ...page.props }, url: page.url, version: 'fixture', clearHistory: false, encryptHistory: false });
document.body.append(script);
void createInertiaApp({ resolve: () => page.component, setup: ({ el, App, props: inertiaProps }) => createRoot(el).render(<App {...inertiaProps} />) });
