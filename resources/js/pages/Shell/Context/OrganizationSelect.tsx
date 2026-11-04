import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Company = { name: string; role: string; brands: { id: string; name: string }[]; lastLogin: string | null; logoUrl: string | null };
function lastLogin(value: string | null): string {
  if (!value) return 'впервые';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return 'нет данных';
  const day = new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
  const time = date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  if (day === today) return `сегодня в ${time}`;
  const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1).getTime();
  if (day === yesterday) return `вчера в ${time}`;
  return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
}
export default function OrganizationSelect({ company }: { company: Company }) {
  const [search, setSearch] = useState('');
  const [logoFailed, setLogoFailed] = useState(false);
  const visible = company.name.toLocaleLowerCase('ru').includes(search.trim().toLocaleLowerCase('ru'));
  const initials = company.name.trim().split(/\s+/).slice(0, 2).map(part => part[0]?.toLocaleUpperCase('ru')).join('');
  return <CabinetLayout breadcrumbs={[{ label: 'Выбор компании' }]}><Head title="Выберите компанию" /><main className="mx-auto max-w-3xl" data-private-profile>
    <h1 className="text-3xl font-bold">Выберите компанию</h1><p className="mt-2 text-(--color-ink-muted)">Откройте бренд, с которым хотите работать</p><p className="mt-3 text-sm text-(--color-ink-muted)">Доступно компаний: 1</p>
    <label className="mt-5 block text-sm font-semibold">Поиск по названию<input type="search" value={search} onChange={event=>setSearch(event.target.value)} className="mt-2 block min-h-11 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3" /></label>
    {visible ? <Link href="/dashboard" className="mt-5 flex cursor-pointer flex-wrap items-center gap-4 rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card) focus-visible:outline-2 focus-visible:outline-(--color-focus)">
      <span className="grid size-14 shrink-0 place-items-center overflow-hidden rounded-xl bg-(--color-surface-muted) text-lg font-bold">{company.logoUrl && !logoFailed ? <img src={company.logoUrl} alt="" onError={()=>setLogoFailed(true)} className="size-full object-contain" /> : initials}</span>
      <span className="min-w-0 flex-1"><strong className="block break-words text-lg">{company.name}</strong><span className="mt-1 block text-sm text-(--color-ink-muted)">{company.role}</span><span className="mt-3 flex flex-wrap items-center gap-2"><span className="text-sm text-(--color-ink-muted)">Бренды</span>{company.brands.map(brand=><span key={brand.id} className="rounded-full bg-(--color-surface-muted) px-3 py-1 text-sm">{brand.name}</span>)}</span><span className="mt-3 block text-sm text-(--color-ink-muted)">Последний вход: {lastLogin(company.lastLogin)}</span></span><span aria-hidden="true">→</span>
    </Link> : <div className="mt-5 rounded-xl bg-(--color-surface-muted) p-5"><p>Ничего не найдено</p><button type="button" className="mt-2 cursor-pointer text-sm font-semibold text-(--color-link)" onClick={()=>setSearch('')}>Сбросить фильтр</button></div>}
  </main></CabinetLayout>;
}
