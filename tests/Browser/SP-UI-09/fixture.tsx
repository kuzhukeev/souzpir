import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Shell/Team/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const selected = new URLSearchParams(location.search).get('filter') ?? 'all';
const empty = { data: [], current_page: 2, last_page: 2, total: 0 };
const page = {
  component: 'Fixture',
  props: {
    members: empty, invitations: { ...empty, as_of: '2026-11-01T12:00:00Z' },
    canViewRoles: false, canIssueInvitations: false, teamBrandRevision: 'a'.repeat(64),
    roleFilter: selected === 'role' ? 'viewer' : 'all', brandFilter: selected === 'brand' ? '01J00000000000000000000003' : 'all',
    statusFilter: selected === 'status' ? 'disabled' : 'all', filterBrands: [{ id: '01J00000000000000000000003', name: 'Тестовый бренд' }],
    theme: 'neutral', brand: { id: '01J00000000000000000000003', name: 'Тестовый бренд', theme: 'neutral' }, brands: [],
    organization: { id: 'o', name: 'Компания' }, auth: { user: { id: 'u', name: 'Владелец', email: 'owner@example.test' } },
    modules: { vision: false, seller: false }, can: {}, unread: null, translations: {}, ziggy: { routes: {} }, errors: {},
  },
  url: '/team', version: null, clearHistory: false, encryptHistory: false,
};
(window as unknown as { teamFixture: typeof page }).teamFixture = page;
const script = document.createElement('script');
script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify(page); document.body.appendChild(script);
void createInertiaApp({ resolve: () => Index, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
