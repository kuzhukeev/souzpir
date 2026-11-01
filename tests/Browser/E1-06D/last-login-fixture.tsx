import { createRoot } from 'react-dom/client';
import { LastLogin } from '../../../resources/js/features/Shell/MemberActivity/LastLogin';
import { useMemberLastLogins } from '../../../resources/js/features/Shell/MemberActivity/useMemberLastLogins';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const ids = ['01J00000000000000000000001', '01J00000000000000000000002'];
const brand = '01J00000000000000000000003';
const revision = 'a'.repeat(64);
document.documentElement.dataset.theme =
  new URLSearchParams(location.search).get('theme') ?? 'neutral';

export function Fixture() {
  const logins = useMemberLastLogins(ids, brand, revision);
  return (
    <main className="min-h-dvh bg-(--color-bg) p-4 text-(--color-ink)">
      <section className="mx-auto max-w-3xl rounded-2xl border border-(--color-border) bg-(--color-surface) p-5">
        <h1 className="text-2xl font-bold">Участники</h1>
        <ul className="mt-5 space-y-3">
          {ids.map((id, index) => (
            <li key={id} className="rounded-xl border border-(--color-border) p-4">
              <p className="text-sm font-semibold">
                {index ? 'Участник без истории' : 'Тестовый участник'}
              </p>
              <p className="mt-2 text-sm">
                Последний вход: <LastLogin status={logins.status} value={logins.values[id]} />
              </p>
            </li>
          ))}
        </ul>
        <button
          type="button"
          onClick={logins.retry}
          className="mt-4 min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm text-(--color-link)"
        >
          Повторить
        </button>
      </section>
    </main>
  );
}

createRoot(document.getElementById('app')!).render(<Fixture />);
