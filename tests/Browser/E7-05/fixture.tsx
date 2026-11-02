import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Dashboard/Index';
import Attention from '../../../resources/js/pages/Seller/Dashboard/Attention';
import type { Dashboard } from '../../../resources/js/pages/Seller/Dashboard/types';

const params = new URLSearchParams(location.search);
const empty = params.has('empty');
const attention = params.has('attention');
const brandId = '01J00000000000000000000004';
const daily = Object.fromEntries(Array.from({ length: 30 }, (_, day) => [`2026-10-${String(day + 1).padStart(2, '0')}`, empty ? 0 : day % 4 + 2]));
const dashboard: Dashboard = { days: 30, total: empty ? 0 : 248, contact: empty ? 0 : 84, handoff: empty ? 0 : 31, done: empty ? 0 : 19, need: empty ? 0 : 164, daily,
  channels: empty ? [] : [{ type: 'telegram', name: 'Telegram', mode: 'bot', status: 'active' }, { type: 'widget', name: 'Виджет сайта', mode: 'suggest', status: 'disabled' }],
  attention: empty ? [] : [{ kind: 'manager', title: 'Диалоги ждут менеджера', count: 5, severity: 'high', href: '/seller/dialogs?mode=manager' }, { kind: 'tasks', title: 'Обещания просрочены', count: 3, severity: 'high', href: '/seller/tasks' }, { kind: 'channels', title: 'Каналы выключены', count: 1, severity: 'normal', href: '/seller/channels' }] };
const props = { dashboard, auth: { user: { id: '1', name: 'Иван Петров', email: 'ivan@example.test' } }, organization: { id: '1', name: 'Союз Пир' },
  brand: { id: brandId, name: 'Пиротехника — Северные огни', theme: 'neutral' }, brands: [{ id: brandId, name: 'Пиротехника — Северные огни' }],
  modules: { seller: true, vision: true }, can: { 'seller.dialogs.read': true, 'seller.dialogues.operate': true }, unread: null, request_id: 'fixture', theme: 'neutral', errors: {},
  ziggy: { routes: Object.fromEntries(['/seller', '/seller/dialogs', '/seller/quality', '/seller/analytics', '/seller/channels', '/seller/integrations/catalog', '/dashboard'].map(path => [path, { uri: path.slice(1), methods: ['GET'] }])) } };
const script = document.createElement('script');
script.type = 'application/json'; script.dataset.page = 'app';
script.textContent = JSON.stringify({ component: 'Fixture', props, url: attention ? '/seller/attention' : '/seller', version: 'fixture', clearHistory: false, encryptHistory: false });
document.body.append(script);
void createInertiaApp({ resolve: () => attention ? Attention : Index, setup: ({ el, App, props: page }) => createRoot(el).render(<App {...page} />) });
