import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Scenarios/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const id = '01JTEST000000000000000001';
const params = new URLSearchParams(location.search);
const scenarios = params.has('empty') ? [] : [
    { id, name: 'Новый клиент: доставка', type: 'newbie', max_turns: 8, behavior: 'Спрашивает о сроках доставки', first_message: 'Когда доставите?', expect: ['full_card', 'no_argument'], editable: true, who: 'Новый покупатель', want: 'Узнать сроки доставки', goal: 'Получить контакт', expected_result: 'contact', in_set: true, last_result: { id: 'run1', persona_id: id, status: 'completed', result: 'passed', average: 4.5, comment: 'Контакт получен', turn_count: 2, turns: [{ client: 'Когда доставите?', agent: 'Уточните город.' }], created_at: '2026-11-03T10:00:00Z' } },
    { id: '01JTEST000000000000000002', name: 'Большой заказ', type: 'experienced', max_turns: 6, behavior: 'Просит скидку', first_message: 'Нужен крупный заказ', expect: ['no_pct_above_grid'], editable: false, who: 'Закупщик', want: 'Получить скидку', goal: 'Передать менеджеру', expected_result: 'handoff', in_set: false, last_result: null },
];
const page = { component: 'Fixture', props: { scenarios, runs: scenarios[0]?.last_result ? [scenarios[0].last_result] : [], status: null, theme: 'neutral', brand: { id: 'brand', name: 'Пример бренда', theme: 'neutral' }, brands: [], organization: { id: 'org', name: 'Компания' }, auth: { user: { id: 'user', name: 'Иван Петров', email: 'ivan@example.test' } }, modules: { seller: true, vision: true }, can: {}, unread: null, translations: {}, ziggy: { routes: {} }, errors: {} }, url: '/seller/quality/scenarios', version: null, clearHistory: false, encryptHistory: false };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify(page); document.body.appendChild(script);
void createInertiaApp({ resolve: () => Index, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
