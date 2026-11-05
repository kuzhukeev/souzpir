import { Head, Link } from '@inertiajs/react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Issue = { type?: string; layer?: string; turn?: number; quote?: string; explanation?: string; fix?: string };
type Evaluation = { test_baseline?: boolean; id: string; conversation_id: string | null; sim_run_id: string | null; scores: Record<string, number>; issues: Issue[]; average: number; comment: string | null };
const labels: Record<string, string> = { naturalness: 'Естественность', goal: 'Достижение цели', facts: 'Точность фактов', objections: 'Работа с возражениями', brevity: 'Краткость' };
const issueLabels: Record<string, string> = { unknown_fact: 'Неизвестный факт', invented_fact: 'Выдуманный факт', missed_escalation: 'Не передано менеджеру', other: 'Другое' };

export default function Evaluation({ evaluation }: { evaluation: Evaluation }) {
  return <CabinetLayout breadcrumbs={[{ label: 'Продавец', href: '/seller' }, { label: 'Качество', href: '/seller/quality' }, { label: 'Оценка диалога' }]}>
    <Head title="Оценка диалога"/><main className="mx-auto max-w-[1000px]" data-private-profile>
      <Link href="/seller/quality" className="cursor-pointer text-sm text-(--color-link)">← К качеству</Link>
      <h1 className="mt-4 text-3xl font-bold">Оценка диалога</h1><p className="mt-2 text-sm text-(--color-ink-muted)">{evaluation.test_baseline ? 'Тестовая оценка, качество не оценивалось' : evaluation.conversation_id ? 'Диалог' : evaluation.sim_run_id ? 'Симуляция' : 'Оценка'} · {evaluation.average.toFixed(1)} из 5</p>
      <section className="mt-6 rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><h2 className="text-xl font-bold">Оценки</h2><dl className="mt-4 space-y-3">{Object.entries(labels).map(([key, label]) => <div key={key} className="flex justify-between gap-3 text-sm"><dt>{label}</dt><dd>{evaluation.scores?.[key] == null ? '—' : `${evaluation.scores[key]} / 5`}</dd></div>)}</dl>{evaluation.comment && <p className="mt-4 whitespace-pre-wrap text-sm">{evaluation.comment}</p>}</section>
      <section className="mt-4 rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><h2 className="text-xl font-bold">Проблемы по ходам</h2>{evaluation.issues?.length ? <ul className="mt-4 space-y-3">{evaluation.issues.map((issue, index) => <li key={index} className="rounded-lg bg-(--color-surface-muted) p-3 text-sm"><strong>Ход {issue.turn ?? '—'} · {issueLabels[issue.type ?? ''] ?? 'Замечание'}</strong>{issue.quote && <p className="mt-2">«{issue.quote}»</p>}{issue.explanation && <p className="mt-2">{issue.explanation}</p>}{issue.fix && <p className="mt-2">Как исправить: {issue.fix}</p>}</li>)}</ul> : <p className="mt-3 text-sm">Замечаний нет.</p>}</section>
      {evaluation.conversation_id && <Link href={`/seller/dialogs/${evaluation.conversation_id}`} className="mt-4 inline-flex min-h-11 cursor-pointer items-center text-sm font-semibold text-(--color-link)">Открыть диалог →</Link>}
    </main>
  </CabinetLayout>;
}
