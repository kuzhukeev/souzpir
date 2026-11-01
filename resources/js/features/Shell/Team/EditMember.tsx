import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { TeamMemberDetail } from '@/types/Shell/Team/members';

export function EditMember({
  member,
  access,
}: {
  member: TeamMemberDetail;
  access: {
    brands: { id: string; name: string }[];
    allBrands: boolean;
    memberAllBrands: boolean;
    revision: string;
  };
}) {
  const [role, setRole] = useState(member.role ?? 'viewer');
  const [brands, setBrands] = useState(member.brands.map((brand) => brand.id));
  const [all, setAll] = useState(access.memberAllBrands);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const save = (status: 'active' | 'disabled') => {
    setBusy(true);
    setError('');
    router.patch(
      `/team/${member.id}`,
      { role, brand_ids: all && role === 'admin' ? [] : brands, status },
      {
        preserveScroll: true,
        headers: { 'X-Brand-Revision': access.revision },
        onError: (errors) => setError(Object.values(errors).join(' ')),
        onFinish: () => setBusy(false),
      },
    );
  };
  return (
    <div className="mt-5 space-y-4 border-t border-(--color-border) pt-5">
      <label className="block text-sm font-semibold">
        Роль
        <select
          value={role}
          onChange={(e) => setRole(e.target.value as typeof role)}
          className="mt-2 block min-h-11 w-full cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3"
        >
          <option value="admin">Администратор</option>
          <option value="editor">Редактор</option>
          <option value="manager">Менеджер</option>
          <option value="viewer">Наблюдатель</option>
        </select>
      </label>
      <fieldset>
        <legend className="text-sm font-semibold">Доступ к брендам</legend>
        {access.allBrands && role === 'admin' && (
          <label className="mt-2 flex cursor-pointer gap-2 text-sm">
            <input type="checkbox" checked={all} onChange={(e) => setAll(e.target.checked)} />
            Все бренды
          </label>
        )}
        {(!all || role !== 'admin') &&
          access.brands.map((brand) => (
            <label key={brand.id} className="mt-2 flex cursor-pointer gap-2 text-sm">
              <input
                type="checkbox"
                checked={brands.includes(brand.id)}
                onChange={(e) =>
                  setBrands(
                    e.target.checked
                      ? [...brands, brand.id]
                      : brands.filter((id) => id !== brand.id),
                  )
                }
              />
              {brand.name}
            </label>
          ))}
        {access.brands.length === 0 && <p className="mt-2 text-sm">Нет доступных брендов</p>}
      </fieldset>
      {error && (
        <p role="alert" className="text-sm text-(--color-danger-ink)">
          {error}
        </p>
      )}
      <div className="flex flex-wrap gap-3">
        <button
          type="button"
          disabled={busy}
          onClick={() => save('active')}
          className="min-h-11 cursor-pointer rounded-lg bg-(--color-action) px-4 text-sm font-semibold text-white disabled:opacity-50"
        >
          {busy ? 'Сохранение…' : 'Сохранить'}
        </button>
        <button
          type="button"
          disabled={busy}
          onClick={() => {
            if (window.confirm('Отключить участника?')) save('disabled');
          }}
          className="min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm font-semibold disabled:opacity-50"
        >
          Отключить
        </button>
      </div>
    </div>
  );
}
