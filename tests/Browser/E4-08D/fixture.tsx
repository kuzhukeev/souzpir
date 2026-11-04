import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Knowledge/Fewshot/Index';

const brandId = '01J00000000000000000000004';
const props = { draft: { version: 3 }, maxWords: 3, fewshot: { examples: [{ tag: 'ask_city', text: 'В какой город отправить заказ?', client_type: 'all', confidence: 0.9, source: 'manual' }, { tag: 'price', text: 'Цена указана на сайте.', client_type: 'all', confidence: 0.8, source: 'manual' }], disabled_examples: [], bad_examples: [{ text: 'Доставка всегда бесплатна.', why: 'Нельзя обещать условия без проверки.' }] },
  auth: { user: { id: '1', name: 'Иван Петров', email: 'ivan@example.test' } }, organization: { id: '1', name: 'Союз Пир' },
  brand: { id: brandId, name: 'Пиротехника — Северные огни', theme: 'neutral' }, brands: [{ id: brandId, name: 'Пиротехника — Северные огни' }],
  modules: { seller: true, vision: true }, can: { 'seller.knowledge.write': true, 'seller.knowledge.read': true, 'seller.dialogs.read': true }, unread: null, request_id: 'fixture', theme: 'neutral', errors: {},
  ziggy: { routes: Object.fromEntries(['/seller', '/seller/dialogs', '/seller/knowledge', '/seller/knowledge/fewshot', '/seller/quality', '/seller/channels'].map(path => [path, { uri: path.slice(1), methods: ['GET'] }])) } };
const script = document.createElement('script');
script.type = 'application/json'; script.dataset.page = 'app';
script.textContent = JSON.stringify({ component: 'Fixture', props, url: '/seller/knowledge/fewshot', version: 'fixture', clearHistory: false, encryptHistory: false });
document.body.append(script);
void createInertiaApp({ resolve: () => Index, setup: ({ el, App, props: page }) => createRoot(el).render(<App {...page} />) });
