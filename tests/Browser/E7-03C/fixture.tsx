import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Compare from '../../../resources/js/pages/Seller/Calibration/Compare';
import Index from '../../../resources/js/pages/Seller/Calibration/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const compare = location.search.includes('compare');
const page = { component: 'Fixture', props: {
  runs: [{ id: 'a', name: 'Сценарий 1', created_at: '2026-11-01T12:00:00Z', average: 3 }, { id: 'b', name: 'Сценарий 2', created_at: '2026-11-02T12:00:00Z', average: 4 }], filters: { before: 'a', after: 'b' },
  comparison: { same_scenario: true, improved: 1, worsened: 0, unchanged: 0, total: 1, items: [{ criterion: 'Точность', before: 3, after: 4, delta: 1 }] },
  items: [{ id: 'a', run_id: null, conversation_id: '01J00000000000000000000001', name: 'Первый прогон', judge: 3.5, rating: 4, comment: '', created_at: '2026-11-01T12:00:00Z' }, { id: 'b', run_id: 'r2', conversation_id: null, name: 'Второй прогон', judge: 4, rating: 2, comment: 'Проверить ответ', created_at: '2026-11-02T12:00:00Z' }],
  theme: 'neutral', brand: { id: 'b', name: 'Пример бренда', theme: 'neutral' }, brands: [], organization: { id: 'o', name: 'Компания' },
  auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } }, modules: { seller: true, vision: false }, can: {}, unread: null, translations: {}, ziggy: { routes: {} }, errors: {},
}, url: location.pathname + location.search, version: null, clearHistory: false, encryptHistory: false };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify(page); document.body.appendChild(script);
void createInertiaApp({ resolve: () => compare ? Compare : Index, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
