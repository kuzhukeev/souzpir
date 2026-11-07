import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Evaluation from '../../../resources/js/pages/Seller/Quality/Evaluation';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const props = { evaluation: { id: 'e', conversation_id: '01ARZ3NDEKTSV4RRFFQ69G5FAV', sim_run_id: null, scores: { naturalness: 4, goal: 5, facts: 3, objections: 4, brevity: 4 }, issues: [{ type: 'other', turn: 2, quote: 'Уточните наличие', explanation: 'Нужно уточнить факт.', fix: 'Обновить каталог' }], average: 4, comment: 'Есть возможность улучшить ответ.' }, theme: 'neutral', brand: { id: 'b', name: 'Пример бренда', theme: 'neutral' }, brands: [], organization: { id: 'o', name: 'Компания' }, auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } }, modules: { seller: true, vision: false }, can: {'seller.settings.write': true}, unread: null, translations: {}, ziggy: { routes: {} }, errors: {} };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app';
script.textContent = JSON.stringify({ component: 'Fixture', props, url: location.pathname, version: null, clearHistory: false, encryptHistory: false }); document.body.appendChild(script);
void createInertiaApp({ resolve: () => Evaluation, setup: ({ el, App, props }) => createRoot(el).render(<App {...props}/>) });

