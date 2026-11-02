import { Head, Link } from '@inertiajs/react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Release = { id: string; status: string; version: number; published_at: string | null };
type Change = { key: string; type: 'added' | 'removed' | 'changed'; before: unknown; after: unknown };
type Section = { totalBefore: number; totalAfter: number; changes: Change[] };
type Props = { from: Release; to: Release; sections: Record<'facts' | 'conditions' | 'topics' | 'examples', Section> };
const names = { facts: 'Факты', conditions: 'Условия', topics: 'Темы', examples: 'Образцы' };
const types = { added: 'Добавлено', removed: 'Удалено', changed: 'Изменено' };
const format = (value: unknown) => value === null ? '—' : typeof value === 'string' ? value : JSON.stringify(value, null, 2);
const releaseName = (release: Release) => release.status === 'draft' ? `Черновик · v${release.version}` : `Публикация ${release.published_at ? new Date(release.published_at).toLocaleString('ru-RU') : ''} · v${release.version}`;

export default function Compare({ from, to, sections }: Props) {
 const total = Object.values(sections).reduce((sum, section) => sum + section.changes.length, 0);
 return <CabinetLayout breadcrumbs={[{label:'Продавец',href:'/seller'},{label:'База знаний',href:'/seller/knowledge'},{label:'Сравнение релизов'}]}><Head title="Сравнение релизов"/><main className="mx-auto max-w-[1500px]" data-private-profile>
  <Link href="/seller/knowledge" className="cursor-pointer text-sm font-semibold text-(--color-link)">← К базе знаний</Link>
  <h1 className="mt-4 text-3xl font-bold">Сравнение релизов</h1>
  <p className="mt-2 text-sm text-(--color-ink-muted)">{releaseName(from)} → {releaseName(to)}</p>
  <p role="status" className="mt-4 rounded-xl border border-(--color-border) bg-(--color-surface) p-4 text-sm">{total ? `Изменений: ${total}` : 'Изменений нет. Релизы совпадают по сравниваемым разделам.'}</p>
  {(Object.keys(names) as (keyof typeof names)[]).map(key => <section key={key} className="mt-4 rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><h2 className="text-xl font-bold">{names[key]}</h2><p className="mt-1 text-sm text-(--color-ink-muted)">Было: {sections[key].totalBefore} · Стало: {sections[key].totalAfter}</p>{sections[key].changes.length === 0 ? <p className="mt-3 text-sm">Без изменений.</p> : <div className="mt-4 space-y-4">{sections[key].changes.map(change => <article key={change.key} className="rounded-lg border border-(--color-border) p-4 text-sm"><h3 className="font-semibold break-all">{types[change.type]} · {change.key}</h3><div className="mt-3 grid gap-3 lg:grid-cols-2"><div className="min-w-0 rounded-lg bg-(--color-danger-soft) p-3"><p className="font-semibold">Было</p><pre className="mt-2 overflow-x-auto whitespace-pre-wrap break-words font-sans text-sm">{format(change.before)}</pre></div><div className="min-w-0 rounded-lg bg-(--color-success-soft) p-3"><p className="font-semibold">Стало</p><pre className="mt-2 overflow-x-auto whitespace-pre-wrap break-words font-sans text-sm">{format(change.after)}</pre></div></div></article>)}</div>}</section>)}
 </main></CabinetLayout>;
}
