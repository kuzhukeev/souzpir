import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Settings/Techniques/Index';

const empty = location.search.includes('empty');
const props = {
  techniques: empty ? [] : [
    { id: 'price_increase_calc', name: 'Подорожание с конкретным расчётом', idea: 'Покажите клиенту конкретный расчёт изменения цены и объясните срок.', examples: ['Сравним стоимость заказа сейчас и через месяц.', 'Зафиксируем цену на текущую партию.'], max_uses_per_dialog: 1, when: { client_type: ['newbie', 'experienced'], stage: ['qualify', 'budget'] }, not_when: { 'signals.wants_only_prices': true }, enabled: true, is_base: true, uses: 18, dialogs: 16, conversion_with: 31, conversion_without: 14 },
    { id: 'shortage_with_reason', name: 'Дефицит с причиной', idea: 'Объясните наличие товара проверенными фактами.', examples: ['Уточним остаток на складе.'], max_uses_per_dialog: 1, when: { client_type: ['experienced'] }, not_when: {}, enabled: false, is_base: false, uses: 0, dialogs: 0, conversion_with: null, conversion_without: null },
  ],
  release: '01J00000000000000000000005', version: 2, draft: true,
  auth: { user: { id: '1', name: 'Иван Петров', email: 'ivan@example.test' } }, organization: { id: '1', name: 'Союз Пир' },
  brand: { id: '01J00000000000000000000004', name: 'Пиротехника — Северные огни', theme: 'neutral' },
  brands: [{ id: '01J00000000000000000000004', name: 'Пиротехника — Северные огни' }], modules: { seller: true, vision: true },
  can: { 'seller.knowledge.write': !new URLSearchParams(location.search).has('viewer') }, ziggy: { routes: { 'seller.techniques.index': { uri: 'seller/scenario/techniques', methods: ['GET'] } } }, unread: null, request_id: 'fixture', theme: 'neutral', errors: {},
};
const script = document.createElement('script');
script.type = 'application/json'; script.dataset.page = 'app';
script.textContent = JSON.stringify({ component: 'Fixture', props, url: '/seller/scenario/techniques', version: 'fixture', clearHistory: false, encryptHistory: false });
document.body.append(script);
void createInertiaApp({ resolve: () => Index, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });

