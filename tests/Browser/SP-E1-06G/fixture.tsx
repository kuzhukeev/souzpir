import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { Sidebar } from '../../../resources/js/components/navigation/Sidebar';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const allowed = new URLSearchParams(location.search).has('allowed');
const page = {
  component: 'Fixture',
  props: {
    brand: { id: 'b', name: 'Тестовый бренд', theme: 'neutral' },
    organization: { id: 'o', name: 'Компания' },
    auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } },
    modules: { vision: false, seller: true },
    can: { 'seller.dialogues.operate': allowed },
    translations: {},
    ziggy: { routes: { 'seller.tasks.transfer.page': { uri: 'seller/tasks/transfer', methods: ['GET', 'HEAD'] } } },
    errors: {},
  },
  url: '/dashboard',
  version: null,
  clearHistory: false,
  encryptHistory: false,
};
const script = document.createElement('script');
script.type = 'application/json';
script.dataset.page = 'app';
script.textContent = JSON.stringify(page);
document.body.appendChild(script);
void createInertiaApp({
  resolve: () => Sidebar,
  setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
