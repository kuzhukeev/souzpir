import { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp, usePage } from '@inertiajs/react';
import type { Page } from '@inertiajs/core';
import InvitationDialog, {
  type InvitationBrand,
} from '@/features/Shell/InvitationIssuance/InvitationDialog';
import '@/../css/app.css';
import.meta.glob('../../../resources/css/themes/**/*.css', { eager: true });

type Context = {
  brand: { id: string };
  filterBrands: InvitationBrand[];
  teamBrandRevision: string;
  canViewRoles: boolean;
};

export function Harness() {
  const currentPage = usePage();
  const initialContext = currentPage.props as unknown as Context;
  const [context, setContext] = useState<Context>(initialContext);
  const [open, setOpen] = useState(false);
  return (
    <main className="p-6" data-inertia-version={currentPage.version ?? ''}>
      <h1>Проверка формы приглашения</h1>
      <button type="button" onClick={() => setOpen(true)}>
        Открыть форму
      </button>
      <button
        type="button"
        onClick={() =>
          setContext({ ...context, teamBrandRevision: `${context.teamBrandRevision}-changed` })
        }
      >
        Сменить контекст
      </button>
      <button type="button" onClick={() => setContext({ ...context, canViewRoles: false })}>
        Отозвать доступ
      </button>
      <InvitationDialog
        open={open}
        onClose={() => setOpen(false)}
        canIssue={context.canViewRoles}
        brandId={context.brand.id}
        revision={context.teamBrandRevision}
        brands={context.filterBrands}
      />
    </main>
  );
}
async function boot() {
  const response = await fetch('/team', { credentials: 'same-origin', cache: 'no-store' });
  if (!response.ok) throw new Error('Context unavailable');
  const document = new DOMParser().parseFromString(await response.text(), 'text/html');
  const content = document.querySelector('script[data-page="app"]')?.textContent;
  if (!content) throw new Error('Inertia context unavailable');
  const page = JSON.parse(content) as Page;
  await createInertiaApp({
    page,
    resolve: () => Harness,
    setup: ({ App, props }) => createRoot(window.document.getElementById('root')!).render(<App {...props} />),
  });
}
void boot().catch(() => {
  window.document.getElementById('root')!.textContent = 'Не удалось получить контекст.';
});
