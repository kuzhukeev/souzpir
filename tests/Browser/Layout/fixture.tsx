import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import CabinetLayout from '../../../resources/js/layouts/CabinetLayout';
const routes = {
  dashboard: { uri: 'dashboard', methods: ['GET'] },
  dialogs: { uri: 'seller/dialogs', methods: ['GET'] },
  switch: { uri: 'brand/switch', methods: ['POST'] },
};
const can = Object.fromEntries(
  [
    'org.read',
    'team.read',
    'billing.read',
    'integrations.read',
    'profile.own',
    'seller.dialogs.read',
    'seller.customers.read',
    'seller.knowledge.read',
    'seller.settings.read',
    'vision.report.read',
    'vision.measure.read',
  ].map((key) => [key, true]),
);
if (location.search.includes('limited=1')) can['seller.knowledge.read'] = false;
const props = {
  auth: { user: { id: 'user', name: 'Иван Петров', email: 'test@example.test' } },
  organization: { id: 'org', name: 'Оптовые поставки пиротехники' },
  brand: { id: 'one', name: 'Пиротехника', theme: 'pyro' },
  brands: [
    { id: 'one', name: 'Пиротехника' },
    { id: 'two', name: 'Второй бренд' },
  ],
  modules: { seller: true, vision: null },
  can,
  unread: null,
  request_id: 'fixture',
  theme: 'pyro',
  translations: {},
  ziggy: {
    routes,
    url: location.origin,
    port: null,
    defaults: {},
    location: { host: location.host, pathname: location.pathname, search: '' },
  },
};
const pageElement = document.createElement('script');
pageElement.type = 'application/json';
pageElement.dataset.page = 'app';
pageElement.textContent = JSON.stringify({
  component: 'Fixture',
  props,
  url: location.search.includes('deep=1')
    ? '/seller/dialogs/01ARZ3NDEKTSV4RRFFQ69G5FAV'
    : '/seller/dialogs',
  version: 'fixture',
  clearHistory: false,
  encryptHistory: false,
});
document.body.append(pageElement);
async function mountFixture() {
  // Use the same already-built CSS as production; avoid a full Tailwind scan on
  // every fresh Vite test server (especially when worktrees are present).
  const response = await fetch('/build/manifest.json');
  if (!response.ok) throw new Error('Build assets before running layout browser tests.');
  const manifest = (await response.json()) as Record<string, { file: string; css?: string[] }>;
  const styles = [
    manifest['resources/css/app.css'].file,
    ...(manifest['resources/js/app.tsx'].css ?? []),
  ];
  await Promise.all(
    styles.map(
      (file) =>
        new Promise<void>((resolve, reject) => {
          const link = document.createElement('link');
          link.rel = 'stylesheet';
          link.href = '/build/' + file;
          link.onload = () => resolve();
          link.onerror = () => reject(new Error('Unable to load layout test stylesheet.'));
          document.head.append(link);
        }),
    ),
  );
  await createInertiaApp({
    resolve: () => () => (
      <CabinetLayout
        brandSwitchReady={location.search.includes('ready=1')}
        breadcrumbs={[{ label: 'Главная', href: '/dashboard' }, { label: 'Диалоги' }]}
      >
        <h1>Диалоги</h1>
      </CabinetLayout>
    ),
    setup: ({ el, App, props: appProps }) => createRoot(el).render(<App {...appProps} />),
  });
}
void mountFixture();
