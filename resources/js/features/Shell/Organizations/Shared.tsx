import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import CabinetLayout from '@/layouts/CabinetLayout';

export const panel = 'rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card) sm:p-6';
export const focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)';
export function Page({ title, subtitle, children }: { title: string; subtitle: string; children: ReactNode }) {
  return <CabinetLayout breadcrumbs={[{ label: 'Главная', href: '/dashboard' }, { label: title }]}><div className="mx-auto max-w-[1500px]"><header className="mb-6"><h1 className="text-3xl font-bold leading-tight">{title}</h1><p className="mt-2 text-base text-(--color-ink-muted)">{subtitle}</p></header>{children}</div></CabinetLayout>;
}
export function Tabs({ active }: { active: 'company' | 'brands' }) {
  return <nav aria-label="Компания и бренды" className="mb-5 flex flex-wrap gap-2 border-b border-(--color-border)"><Link href="/company" aria-current={active === 'company' ? 'page' : undefined} className={`cursor-pointer px-4 py-3 text-sm font-semibold ${active === 'company' ? 'border-b-2 border-(--color-action) text-(--color-action)' : 'text-(--color-link)'} ${focus}`}>Компания</Link><Link href="/brands" aria-current={active === 'brands' ? 'page' : undefined} className={`cursor-pointer px-4 py-3 text-sm font-semibold ${active === 'brands' ? 'border-b-2 border-(--color-action) text-(--color-action)' : 'text-(--color-link)'} ${focus}`}>Бренды</Link></nav>;
}
export function Errors({ errors, transport }: { errors: Record<string, string | undefined>; transport: string }) {
  const messages = Array.from(new Set([transport, ...Object.values(errors)].filter(Boolean)));
  return messages.length ? <div role="alert" className="mb-4 rounded-xl bg-(--color-danger-soft) p-3 text-sm text-(--color-danger-ink)">{messages.map((message) => <p key={message}>{message}</p>)}</div> : null;
}
