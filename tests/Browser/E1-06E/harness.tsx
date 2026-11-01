import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
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
  const [context, setContext] = useState<Context | null>(null);
  const [open, setOpen] = useState(false);
  const [error, setError] = useState('');
  useEffect(() => {
    fetch('/team', {
      headers: { 'X-Inertia': 'true', Accept: 'text/html, application/xhtml+xml' },
      credentials: 'same-origin',
      cache: 'no-store',
    })
      .then(async (response) => {
        if (!response.ok) throw new Error();
        const page = (await response.json()) as { props: Context };
        setContext(page.props);
      })
      .catch(() => setError('Не удалось получить контекст.'));
  }, []);
  if (!context) return <p>{error || 'Загружаем контекст…'}</p>;
  return (
    <main className="p-6">
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
createRoot(document.getElementById('root')!).render(<Harness />);
