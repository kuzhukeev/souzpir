import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Run from '../../../resources/js/pages/Seller/Knowledge/Releases/Run';
import examples from '../../../app/ProtectedOperations/DraftSimulation/fixtures/seeded-context-e401t.json' with { type: 'json' };
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const criterion = new URLSearchParams(location.search).get('case') === 'unknown' ? 'no_pct_above_grid' : 'no_deferred_promise';
const example = examples.find(item => item.criterion === criterion)!;
const dialogue = example.dialogue as { role: string; text: string }[];
const turns = Array.from({ length: dialogue.length / 2 }, (_, index) => ({
    client: dialogue[index * 2].text,
    agent: dialogue[index * 2 + 1].text,
    meta: index === dialogue.length / 2 - 1
        ? example.expected === null ? { semantic_available: false } : { expectation_checks: { [example.criterion]: true } }
        : {},
}));
const run = { id: '01ARZ3NDEKTSV4RRFFQ69G5FAY', created_at: '2026-11-04T10:00:00Z', turns };
const props = { run, theme: 'neutral', brand: { id: 'b', name: 'Учебный бренд', theme: 'neutral' }, brands: [], organization: { id: 'o', name: 'Компания' }, auth: { user: { id: 'u', name: 'Иван', email: 'ivan@example.test' } }, can: {}, unread: null, translations: {}, ziggy: { routes: {} }, errors: {} };
const script = document.createElement('script');
script.type = 'application/json';
script.dataset.page = 'app';
script.textContent = JSON.stringify({ component: 'Fixture', props, url: '/seller/knowledge/runs/' + run.id, version: null, clearHistory: false, encryptHistory: false });
document.body.appendChild(script);
void createInertiaApp({ resolve: () => Run, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
