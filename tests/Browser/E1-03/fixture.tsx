import { createInertiaApp } from '@inertiajs/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot } from 'react-dom/client';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import CabinetLayout from '../../../resources/js/layouts/CabinetLayout';

const brand = { id: 'brand-a', name: 'Первый бренд', theme: 'neutral' as const };
const brands = [brand, { id: 'brand-b', name: 'Второй бренд' }];
const client = new QueryClient();
client.setQueryData(['brand', 'org', 'brand-a', 'private'], { secret: 'old-brand' });
(window as Window & { brandCache?: QueryClient }).brandCache = client;
const props = {
  auth: { user: { id: 'user', name: 'Иван', email: 'ivan@example.test' } },
  organization: { id: 'org', name: 'Компания' }, brand, brands,
  modules: { seller: true, vision: true }, can: {}, theme: 'neutral',
  ziggy: { routes: { dashboard: { uri: 'dashboard', methods: ['GET'] } } },
  translations: {}, errors: {}, unread: null, brandAppearance: null, request_id: 'fixture',
};
const script = document.createElement('script');
script.type = 'application/json';
script.dataset.page = 'app';
script.textContent = JSON.stringify({ component: 'Fixture', props, url: '/dashboard', version: 'fixture', clearHistory: false, encryptHistory: false });
document.body.append(script);
void createInertiaApp({
  resolve: () => () => {
    const content = <CabinetLayout><main className="mx-auto max-w-5xl p-8" data-private-profile><h1 className="text-3xl font-bold">Главная</h1><p className="mt-8">Данные первого бренда</p></main></CabinetLayout>;
    return new URLSearchParams(location.search).has('no-provider') ? content : <QueryClientProvider client={client}>{content}</QueryClientProvider>;
  },
  setup: ({ el, App, props: page }) => createRoot(el).render(<App {...page} />),
});
