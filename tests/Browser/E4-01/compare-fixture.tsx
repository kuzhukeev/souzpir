import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Knowledge/Releases/Index';
import Compare from '../../../resources/js/pages/Seller/Knowledge/Releases/Compare';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const first = { id: '01K00000000000000000000001', status: 'published', version: 2, published_at: '2026-11-01T12:00:00Z', updated_at: '2026-11-01T12:00:00Z' };
const second = { id: '01K00000000000000000000002', status: 'published', version: 3, published_at: '2026-11-02T12:00:00Z', updated_at: '2026-11-02T12:00:00Z' };
const comparison = location.search.includes('compare');
const page = {
  component: 'Fixture',
  props: {
    ...(comparison ? {
      from: first, to: second,
      sections: {
        facts: { totalBefore: 1, totalAfter: 1, changes: [{ key: 'facts:price', type: 'changed', before: { text: 'Срок — 5 дней' }, after: { text: 'Срок — 3 дня' } }] },
        conditions: { totalBefore: 1, totalAfter: 2, changes: [{ key: 'conditions:delivery', type: 'added', before: null, after: { text: 'Доставка по России' } }] },
        topics: { totalBefore: 2, totalAfter: 2, changes: [] },
        examples: { totalBefore: 1, totalAfter: 1, changes: [] },
      },
    } : {
      releases: [second, first], draft: null, activeId: second.id, counts: { facts: 1, intents: 2, triggers: 0, closed_topics: 0 },
      tasks: [], reviews: [], evaluations: [], canWrite: true, status: null,
    }),
    theme: 'neutral', brand: { id: 'b', name: 'Пример бренда', theme: 'neutral' }, brands: [],
    organization: { id: 'o', name: 'Компания' },
    auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } },
    modules: { seller: true, vision: false }, can: {}, unread: null, translations: {}, ziggy: { routes: {} }, errors: {},
  },
  url: location.pathname + location.search, version: null, clearHistory: false, encryptHistory: false,
};
const script = document.createElement('script');
script.type = 'application/json';
script.dataset.page = 'app';
script.textContent = JSON.stringify(page);
document.body.appendChild(script);
void createInertiaApp({ resolve: () => comparison ? Compare : Index, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
