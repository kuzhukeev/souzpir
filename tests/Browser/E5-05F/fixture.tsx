import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Customers/Index';
import Show from '../../../resources/js/pages/Seller/Customers/Show';
import Tasks from '../../../resources/js/pages/Seller/Customers/Tasks';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const screen = new URLSearchParams(location.search).get('screen') ?? 'index';
const components = { index: Index, show: Show, tasks: Tasks };
const task = { id: 'task', contact_id: 'contact', contact_name: 'Анна Смирнова', conversation_id: 'dialog', type: 'promise', payload: '{"text":"Отправить прайс"}', due_at: '2026-11-04T12:00:00Z', status: 'new', priority: 'normal', assigned_user_id: 'user', assignee_name: 'Иван Петров', overdue: false, can_read_dialog: true, channel: 'telegram', messages: [] };
const props = {
  customers: [{ id: 'contact', name: 'Анна Смирнова', company: 'Фейерверк', phone: '+79990000000', email: 'anna@example.test', city: 'Тверь', type: 'experienced', stage: 'contact', outcome: 'full_contact', best_outcome: 'full_contact', conversation_id: 'dialog', manager: 'Иван Петров', channel: 'telegram', channels: ['widget', 'telegram'], tasks: 1, last_at: '2026-11-03T12:00:00Z' }],
  counts: { total: 1, contact: 1, handoff: 0, stale: 0 }, filters: {}, pagination: { page: 1, perPage: 20, total: 1, lastPage: 1 },
  customer: { id: 'contact', name: 'Анна Смирнова', company: 'Фейерверк', city: 'Тверь', phone: '+79990000000', email: 'anna@example.test', external_ids: { telegram: '12345' } },
  clientType: 'experienced', slots: { city: 'Тверь', purpose: 'Оптовая закупка' }, history: [{ slot: 'city', value: 'Тверь', role: 'client', at: '2026-11-03T12:00:00Z' }],
  conversations: [{ id: 'dialog', stage: 'contact', outcome: 'full_contact', mode: 'bot', channel: 'telegram', updated_at: '2026-11-03T12:00:00Z' }], tasks: [task], notes: [{ id: 'note', body: 'Позвонить завтра', author_id: 'user', author_name: 'Иван Петров', created_at: '2026-11-03T12:00:00Z' }],
  canWrite: true, manager: 'Иван Петров', managers: [{ id: 'user', name: 'Иван Петров' }], assignedUserId: 'user', canAssign: true, canReadDialogs: true,
  consent: { status: 'none', unsubscribed: 0, canEdit: true },
  theme: 'neutral', brand: { id: 'brand', name: 'Пример бренда', theme: 'neutral' }, brands: [], organization: { id: 'org', name: 'Компания' }, auth: { user: { id: 'user', name: 'Иван Петров', email: 'ivan@example.test' } }, modules: { seller: true, vision: true }, can: { 'seller.dialogs.read': true, 'seller.settings.write': true }, unread: null, translations: {}, ziggy: { routes: {} }, errors: {},
};
const page = { component: 'Fixture', props, url: `/seller/customers/${screen}`, version: null, clearHistory: false, encryptHistory: false };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify(page); document.body.appendChild(script);
void createInertiaApp({ resolve: () => components[screen as keyof typeof components] ?? Index, setup: ({ el, App, props: pageProps }) => createRoot(el).render(<App {...pageProps} />) });
