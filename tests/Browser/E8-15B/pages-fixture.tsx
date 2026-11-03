import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Vision/Questions/Index';
import Approve from '../../../resources/js/pages/Vision/Questions/Approve';
import Active from '../../../resources/js/pages/Vision/Questions/Active';
import History from '../../../resources/js/pages/Vision/Questions/History';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const params = new URLSearchParams(location.search),
  screen = params.get('screen') ?? 'index',
  theme = params.get('theme') ?? 'neutral';
const pages = { index: Index, approve: Approve, active: Active, history: History } as const;
const page = {
  component: 'Fixture',
  props: {
    theme,
    brand: { id: 'b', name: 'Пример бренда', theme },
    brands: [],
    organization: { id: 'o', name: 'Компания' },
    auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } },
    modules: { vision: true, seller: false },
    can: { 'vision.quiz.write': true, 'vision.measure.run': true }, measurementBrandRevision: 'a'.repeat(64),
    unread: null,
    translations: {},
    ziggy: { routes: {} },
    errors: {},
  },
  url: '/vision/questions',
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
  resolve: () => pages[screen as keyof typeof pages],
  setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});

