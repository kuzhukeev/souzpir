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
      releases: [second, first], draft: { id:'draft', status:'draft', version:4, published_at:null, updated_at:'2026-11-02' }, activeId: second.id, counts: { facts: 1, conditions: 3, intents: 2, examples: 4, closed_topics: 1 }, changeCount: 2, coverage: 75, topics: [{id:'delivery',label:'Доставка'}], changes:[{section:'facts',type:'changed',name:'facts:delivery'}],
      tasks: [{ id: 't1', conversation_id: 'dialog1', payload: '{"question":"Сколько доставка?"}', created_at: '2026-11-01' }, { id: 't2', conversation_id: 'dialog2', payload: '{"question":"Сколько доставка?"}', created_at: '2026-11-02' }], reviews: [{id:'r', note:'Проверить цену', better_text:null}], evaluations: [], canWrite: true, status: null,
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
