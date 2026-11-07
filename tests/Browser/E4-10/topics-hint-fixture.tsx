import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Knowledge/Topics/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const props = { topics: [], facts: [], frequencies: {}, unknownRates: {}, frequentQuestions: {}, canWrite: true, draftVersion: 2, status: null, theme: 'neutral', brand: { id: 'b', name: 'Пример бренда', theme: 'neutral' }, brands: [], organization: { id: 'o', name: 'Компания' }, auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } }, modules: { seller: true, vision: false }, can: {}, unread: null, translations: {}, ziggy: { routes: {} }, errors: {} };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app';
script.textContent = JSON.stringify({ component: 'Fixture', props, url: location.pathname, version: null, clearHistory: false, encryptHistory: false }); document.body.appendChild(script);
void createInertiaApp({ resolve: () => Index, setup: ({ el, App, props }) => createRoot(el).render(<App {...props}/>) });
