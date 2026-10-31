import { createInertiaApp, router } from '@inertiajs/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { createQueryClient, sessionCacheSync } from '@/lib/query/client';
import { CheckCircle2 } from 'lucide-react';
import type { ComponentType } from 'react';
import { createRoot } from 'react-dom/client';

// F-01 owns theme files. Discovery allows adding them without editing this entrypoint.
import.meta.glob('../css/themes/**/*.css', { eager: true });
const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx');

// This bootstrap fallback intentionally stays in the owned entrypoint until UI pages land.
// eslint-disable-next-line react-refresh/only-export-components
function FoundationPreview() {
  return (
    <main className="mx-auto my-[8vh] max-w-[720px] p-8">
      <CheckCircle2 aria-hidden="true" size={36} />
      <h1 className="mt-6 mb-3 text-[clamp(24px,4vw,36px)]">Основа кабинета «Союз Пир» готова</h1>
      <p className="leading-relaxed">
        Локальный предпросмотр. Здесь появятся первые макеты входа и рабочего кабинета.
      </p>
      <p className="leading-relaxed">
        Авторизация, отправка сообщений и бизнес-действия пока не подключены.
      </p>
    </main>
  );
}

void createInertiaApp({
  title: (title) => (title ? `${title} — Союз Пир` : 'Союз Пир'),
  resolve: async (name) => {
    if (name === 'Foundation/Smoke') return FoundationPreview;
    const page = pages[`./pages/${name}.tsx`];
    if (!page) throw new Error(`Inertia page is not installed: ${name}`);
    return (await page()).default;
  },
  setup({ el, App, props }) {
    const client = createQueryClient();
    const onNavigation = sessionCacheSync(client, props.initialPage.props);
    router.on('success', (event) => onNavigation(event.detail.page.props));
    createRoot(el).render(
      <QueryClientProvider client={client}>
        <App {...props} />
      </QueryClientProvider>,
    );
  },
  progress: { color: '#235c41' },
});
