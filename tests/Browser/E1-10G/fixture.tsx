import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Api from '../../../resources/js/pages/Shell/Integrations/Api';
import ApiDocumentation from '../../../resources/js/pages/Shell/Integrations/ApiDocumentation';
import Notifications from '../../../resources/js/pages/Shell/Integrations/Notifications';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const screen = new URLSearchParams(location.search).get('screen') ?? 'api';
const components = { api: Api, docs: ApiDocumentation, notifications: Notifications };
const props = { keys: [], webhooks: [], webhookEvents: [], permissions: { manage: true }, apiBrandReadConfigured: true, apiKeyRuntimeAvailable: false, webhookDeliveryAvailable: false, secureSecretTransport: true, secretStorageReady: true, integration_api_key_available: false, integration_webhook_secret_available: false,
  telegram_configured: false, telegram_mask: null, report_emails: [], matrix: { handoff: { cabinet: true, email: false, telegram: false }, unknown_question: { cabinet: true, email: true, telegram: false }, report_published: { cabinet: false, email: true, telegram: false }, limit_80: { cabinet: true, email: false, telegram: false } },
  theme: 'neutral', brand: { id: 'brand', name: 'Пример бренда', theme: 'neutral' }, brands: [], organization: { id: 'org', name: 'Компания' }, auth: { user: { id: 'user', name: 'Иван Петров', email: 'ivan@example.test' } }, modules: { seller: true, vision: true }, can: {}, unread: null, translations: {}, ziggy: { routes: {} }, errors: {} };
const page = { component: 'Fixture', props, url: `/integrations/${screen}`, version: null, clearHistory: false, encryptHistory: false };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify(page); document.body.appendChild(script);
void createInertiaApp({ resolve: () => components[screen as keyof typeof components] ?? Api, setup: ({ el, App, props: pageProps }) => createRoot(el).render(<App {...pageProps} />) });
