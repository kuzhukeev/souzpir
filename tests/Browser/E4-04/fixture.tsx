import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Topics from '../../../resources/js/pages/Seller/Knowledge/Topics/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const page = {
  component: 'Fixture',
  props: {
    topics: [
      { id: 'ask_delivery', title: 'Доставка', description: 'Когда клиент спрашивает о доставке и сроках получения заказа.', examples: ['Как доставляете?', 'Сколько ждать?'], facts: ['delivery_tk', 'delivery_time'], default_answer: 'Уточню условия доставки у менеджера.', after_action: 'bridge', after_action_text: 'В какой город нужна доставка?' },
      { id: 'ask_payment', title: 'Оплата', description: 'Способы оплаты и предоплата.', examples: ['Как оплатить?'], facts: ['payment_methods'], default_answer: '', after_action: 'none', after_action_text: '' },
      { id: 'ask_documents', title: 'Документы', description: 'Документы к заказу.', examples: ['Какие документы нужны?'], facts: [], default_answer: '', after_action: 'none', after_action_text: '' },
    ],
    facts: [{ id: 'delivery_tk', label: 'Транспортные компании', count: 2 }, { id: 'delivery_time', label: 'Сроки доставки', count: 1 }, { id: 'payment_methods', label: 'Оплата', count: 2 }],
    frequencies: { ask_delivery: 42, ask_payment: 30, ask_documents: 10 }, frequentQuestions: {}, canWrite: true, draftVersion: 4, status: null,
    theme: 'neutral', brand: { id: 'b', name: 'Пример бренда', theme: 'neutral' }, brands: [], organization: { id: 'o', name: 'Компания' },
    auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } }, modules: { seller: true, vision: false },
    can: {}, unread: null, translations: {}, ziggy: { routes: {} }, errors: {},
  },
  url: location.pathname + location.search, version: null, clearHistory: false, encryptHistory: false,
};
const script = document.createElement('script');
script.type = 'application/json';
script.dataset.page = 'app';
script.textContent = JSON.stringify(page);
document.body.appendChild(script);
void createInertiaApp({ resolve: () => Topics, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
