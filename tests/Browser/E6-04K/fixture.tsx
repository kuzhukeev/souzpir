import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Tools from '../../../resources/js/pages/Seller/Settings/Tools/Tools';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const tools = { send_price: true, calc_delivery: true, set_followup: true, create_promise: true, escalate: true, mark_do_not_disturb: true, check_stock: false, log_unknown_question: true, send_requisites: true };
const callCounts = { send_price: 3, calc_delivery: 7, set_followup: 2, create_promise: 5, escalate: 1, mark_do_not_disturb: null, check_stock: null, log_unknown_question: 4, send_requisites: 1 };
const props = { settings: { tools, requisites: '', delivery_rules: [] }, callCounts, toolsLoadError: false, status: null, theme: 'neutral', brand: { id: 'b', name: 'Пример бренда', theme: 'neutral' }, brands: [], organization: { id: 'o', name: 'Компания' }, auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } }, modules: { seller: true, vision: false }, can: {}, unread: null, translations: {}, ziggy: { routes: {} }, errors: {} };
const script = document.createElement('script');
script.type = 'application/json'; script.dataset.page = 'app';
script.textContent = JSON.stringify({ component: 'Fixture', props, url: location.pathname, version: null, clearHistory: false, encryptHistory: false });
document.body.appendChild(script);
void createInertiaApp({ resolve: () => Tools, setup: ({ el, App, props }) => createRoot(el).render(<App {...props}/>) });
