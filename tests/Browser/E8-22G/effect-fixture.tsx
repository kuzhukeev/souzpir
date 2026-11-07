import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Effect from '../../../resources/js/pages/Vision/Actions/Effect';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const items = location.search.includes('empty') ? [] : [
  {id: 'a', title: 'Обновить описание доставки', description: 'Изменили условия и сроки доставки для разных регионов', completed_at: '2026-10-12', completed_by_name: 'Мария Волкова', before: {id: 'r1', date: '2026-10-05', visibility: 31}, after: {id: 'r2', date: '2026-10-20', visibility: 40}, before_number: 3, after_number: 4, delta: 9, status: 'growth'},
  {id: 'b', title: 'Уточнить карточку компании', description: 'Добавили информацию о компании, лицензиях и контактах', completed_at: '2026-10-10', completed_by_name: 'Иван Петров', before: {id: 'r1', date: '2026-10-05', visibility: 25}, after: {id: 'r2', date: '2026-10-20', visibility: 25}, before_number: 3, after_number: 4, delta: 0, status: 'no_change'},
  {id: 'c', title: 'Обновить страницу каталога', description: 'Добавили описание категории и примеры товаров', completed_at: '2026-10-27', completed_by_name: 'Мария Волкова', before: null, after: null, before_number: 4, after_number: null, delta: null, status: 'too_early'},
];
const page = {
  component: 'Effect',
  props: {
    theme: 'neutral', brand: {id: 'b', name: 'Пример бренда', theme: 'neutral'}, brands: [], organization: {id: 'o', name: 'Компания'},
    auth: {user: {id: 'u', name: 'Иван Петров', email: 'ivan@example.test'}},
    can: {'vision.actions.write': true, 'vision.measure.run': true, 'vision.report.read': true, 'vision.quiz.write': true},
    modules: {vision: true, seller: false}, visionProfileReady: true,
    unread: null, translations: {}, ziggy: {routes: {}}, errors: {},
    items,
  },
  url: '/vision/actions/effect',
  version: null,
  clearHistory: false,
  encryptHistory: false,
};
const script = document.createElement('script');
script.type = 'application/json';
script.dataset.page = 'app';
script.textContent = JSON.stringify(page);
document.body.appendChild(script);
void createInertiaApp({ resolve: () => Effect, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
