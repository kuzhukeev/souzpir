import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Knowledge/Releases/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const now = '2026-11-03T12:00:00Z';
const props = {
  releases: [{ id: 'draft', status: 'draft', version: 3, published_at: null, updated_at: now, author: 'Иван Петров' }, { id: 'published', status: 'published', version: 2, published_at: now, updated_at: now, author: 'Иван Петров' }],
  draft: { id: 'draft', status: 'draft', version: 3, published_at: null, updated_at: now, author: 'Иван Петров' }, activeId: 'published',
  counts: { facts: 12, conditions: 4, intents: 8, examples: 6, closed_topics: 2 },
  tasks: [{ id: 'question', conversation_id: 'dialog', payload: '{"question":"Когда доставка?"}', created_at: now }],
  reviews: [{ id: 'review', note: 'Срок доставки указан неверно', better_text: 'Доставка за 3–5 дней', conversation_id: 'dialog' }],
  evaluations: [], topics: [], canWrite: true, status: null, changeCount: 1, changes: [{ section: 'facts', type: 'changed', name: 'delivery' }],
  comparison: { facts: { changes: [{ key: 'delivery', type: 'changed', before: '3 дня', after: '3–5 дней' }] } }, coverage: 75, publicationNotes: {},
  theme: 'neutral', brand: { id: 'brand', name: 'Пример бренда', theme: 'neutral' }, brands: [], organization: { id: 'org', name: 'Компания' }, auth: { user: { id: 'user', name: 'Иван Петров', email: 'ivan@example.test' } }, modules: { seller: true, vision: true }, can: {}, unread: null, translations: {}, ziggy: { routes: {} }, errors: {},
};
const page = { component: 'Fixture', props, url: '/seller/knowledge', version: null, clearHistory: false, encryptHistory: false };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify(page); document.body.appendChild(script);
void createInertiaApp({ resolve: () => Index, setup: ({ el, App, props: pageProps }) => createRoot(el).render(<App {...pageProps} />) });
