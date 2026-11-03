import type { ReactNode } from 'react';
import { Check, CircleHelp, Eye, Settings2, Sparkles } from 'lucide-react';

const steps = ['Компания', 'Сайт и бренд', 'Модули', 'Первый шаг'];
export const focusClass = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)';
export const panelClass = 'rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card) sm:p-6';

export function OnboardingFrame({ step, title, subtitle, saving, onSave, children, aside, footer, readOnly = false }: { step: number; title: string; subtitle: string; saving: boolean; onSave: () => void; readOnly?: boolean; children: ReactNode; aside: ReactNode; footer: ReactNode }) {
  return <div className="min-h-dvh bg-(--color-bg) text-(--color-ink)">
    <header className="border-b border-(--color-border) bg-(--color-surface)"><div className="mx-auto flex min-h-[72px] max-w-[1540px] items-center gap-4 px-4 sm:px-8 lg:px-12"><div className="flex items-center gap-2.5 font-bold"><span className="grid size-10 place-items-center rounded-xl bg-(--color-brand-primary) text-white"><Eye size={22} aria-hidden="true" /></span><span className="text-xl">eyon<span className="text-(--color-brand-primary)">.</span></span></div><div className="ml-auto flex items-center gap-3 text-sm"><span className="hidden items-center gap-2 text-(--color-ink-muted) sm:flex"><Settings2 size={18} aria-hidden="true" /> Настройка кабинета</span>{!readOnly && <button type="button" disabled={saving} onClick={onSave} className={`cursor-pointer rounded-lg px-3 py-2 font-medium text-(--color-link) hover:bg-(--color-surface-muted) disabled:cursor-wait disabled:opacity-60 ${focusClass}`}>Сохранить и выйти</button>}</div></div></header>
    <main className="mx-auto max-w-[1540px] px-4 py-7 sm:px-8 lg:px-12 lg:py-9">
      <div className="mb-4 text-sm text-(--color-ink-muted)">EYON <span aria-hidden="true" className="px-2">/</span> <span className="text-(--color-link)">Первый запуск</span></div>
      <div className="mb-5 flex flex-wrap items-end justify-between gap-3"><div><h1 className="text-[clamp(28px,3vw,40px)] leading-tight font-bold">{title}</h1><p className="mt-2 text-base text-(--color-ink-muted)">{subtitle}</p></div><p className="text-sm text-(--color-ink-muted)">Шаг {step} из 4</p></div>
      <div role="progressbar" aria-label="Прогресс настройки" aria-valuemin={1} aria-valuemax={4} aria-valuenow={step} className="mb-6 h-2 overflow-hidden rounded-full bg-(--color-border)"><div className="h-full rounded-full bg-(--color-success) transition-[width] motion-reduce:transition-none" style={{ width: `${step * 25}%` }} /></div>
      <div className="grid items-stretch gap-4 lg:grid-cols-[220px_minmax(0,1fr)] xl:grid-cols-[250px_minmax(0,1fr)_300px]">
        <nav aria-label="Шаги настройки" className={`${panelClass} p-3 sm:p-3`}><ol className="grid grid-cols-2 gap-1 sm:grid-cols-4 lg:grid-cols-1">{steps.map((label, i) => { const n = i + 1; return <li key={label} aria-current={n === step ? 'step' : undefined} className={`flex min-h-12 items-center gap-3 rounded-xl px-3 py-2 text-sm ${n === step ? 'bg-(--color-info-soft) font-semibold text-(--color-link)' : 'text-(--color-ink-muted)'}`}><span className={`grid size-8 shrink-0 place-items-center rounded-full border ${n < step ? 'border-transparent bg-(--color-success-soft) text-(--color-success)' : n === step ? 'border-transparent bg-(--color-link) text-white' : 'border-(--color-border)'}`}>{n < step ? <Check size={17} aria-label="Пройден" /> : n}</span><span>{label}</span></li>; })}</ol></nav>
        <section aria-label={title} className={panelClass}>{children}</section>
        <aside className={`${panelClass} hidden xl:block`}>{aside}</aside>
      </div>
      <div className={`${panelClass} mt-5 flex flex-wrap items-center justify-between gap-3 py-4 sm:py-4`}>{footer}</div>
    </main>
  </div>;
}

export function AsideInfo({ title, children }: { title: string; children: ReactNode }) {
  return <div className="flex items-start gap-3"><span className="grid size-10 shrink-0 place-items-center rounded-full bg-(--color-info-soft) text-(--color-link)"><CircleHelp size={20} aria-hidden="true" /></span><div><h2 className="font-bold">{title}</h2><div className="mt-2 text-sm leading-6 text-(--color-ink-muted)">{children}</div></div></div>;
}
export function Hint({ children }: { children: ReactNode }) { return <div className="mt-6 flex items-start gap-3 border-t border-(--color-border) pt-5 text-sm leading-6 text-(--color-ink-muted)"><Sparkles size={19} className="mt-0.5 shrink-0 text-(--color-brand-accent)" aria-hidden="true" /><p>{children}</p></div>; }
