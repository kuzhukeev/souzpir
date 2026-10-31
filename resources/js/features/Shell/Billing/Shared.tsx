import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Page, focus } from '@/features/Shell/Organizations/Shared';
import type { BillingTab } from '@/types/Shell/Billing';

export const panel = 'rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card) sm:p-6';
const tabs: { id: BillingTab; label: string; href: string; owner?: boolean }[] = [
  { id: 'overview', label: 'Текущий план', href: '/billing' },
  { id: 'usage', label: 'Использование', href: '/billing/usage' },
  { id: 'plans', label: 'Смена тарифа', href: '/billing/plans', owner: true },
  { id: 'invoices', label: 'Счета и оплата', href: '/billing/invoices', owner: true },
  { id: 'payer', label: 'Реквизиты', href: '/billing/payer', owner: true },
];
export function BillingPage({ active, canManage, title, subtitle, children }: { active: BillingTab; canManage: boolean; title: string; subtitle: string; children: ReactNode }) {
  return <Page title={title} subtitle={subtitle}>
    <nav aria-label="Тариф и оплата" className="mb-6 flex flex-wrap gap-1 border-b border-(--color-border)">
      {tabs.filter((tab) => !tab.owner || canManage).map((tab) => <Link key={tab.id} href={tab.href} aria-current={tab.id === active ? 'page' : undefined} className={`cursor-pointer px-3 py-3 text-sm font-semibold sm:px-4 ${tab.id === active ? 'border-b-2 border-(--color-action) text-(--color-action)' : 'text-(--color-link)'} ${focus}`}>{tab.label}</Link>)}
    </nav>{children}
  </Page>;
}
export function Notice({ children }: { children: ReactNode }) {
  return <div className="mb-5 rounded-xl bg-(--color-info-soft) p-4 text-sm leading-6 text-(--color-info-ink)">{children}</div>;
}
