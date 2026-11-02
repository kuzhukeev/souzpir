import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Condition = Record<string, string | string[] | boolean | null | object>;
type Technique = { id: string; name: string; idea: string; examples: string[]; max_uses_per_dialog: number; when: Condition; not_when: Condition; enabled: boolean; is_base: boolean; uses: number; dialogs: number; conversion_with: number | null; conversion_without: number | null };
type Row = { field: string; values: string };
type Props = { techniques: Technique[]; release: string | null; version: number; draft: boolean };

const rows = (condition: Condition): Row[] => Object.entries(condition).filter(([key]) => key !== 'any_of').map(([field, value]) => ({ field, values: Array.isArray(value) ? value.join(', ') : String(value ?? '') }));
const percent = (value: number | null) => value === null ? 'Недостаточно данных' : `${value}%`;

function Editor({ technique, version, onDone }: { technique: Technique; version: number; onDone: (message: string) => void }) {
  const [name, setName] = useState(technique.name);
  const [idea, setIdea] = useState(technique.idea);
  const [examples, setExamples] = useState(technique.examples.join('\n'));
  const [maxUses, setMaxUses] = useState(technique.max_uses_per_dialog);
  const [enabled, setEnabled] = useState(technique.enabled);
  const [isBase, setIsBase] = useState(technique.is_base);
  const [when, setWhen] = useState<Row[]>(rows(technique.when));
  const [notWhen, setNotWhen] = useState<Row[]>(rows(technique.not_when));
  const [pending, setPending] = useState(false);
  const [error, setError] = useState('');
  const update = (list: Row[], setList: (value: Row[]) => void, index: number, field: keyof Row, value: string) => setList(list.map((row, position) => position === index ? { ...row, [field]: value } : row));
  const conditions = (list: Row[], setList: (value: Row[]) => void, label: string) => <fieldset className="mt-5 space-y-2"><legend className="text-sm font-semibold">{label}</legend>{list.map((row, index) => <div key={index} className="flex flex-wrap gap-2"><input aria-label={`${label}: поле ${index + 1}`} value={row.field} onChange={(event) => update(list, setList, index, 'field', event.target.value)} placeholder="stage / slots.city" className="min-w-40 flex-1 rounded-lg border border-(--color-border) bg-(--color-surface) px-3 py-2 text-sm" /><input aria-label={`${label}: значения ${index + 1}`} value={row.values} onChange={(event) => update(list, setList, index, 'values', event.target.value)} placeholder="Значения через запятую" className="min-w-40 flex-1 rounded-lg border border-(--color-border) bg-(--color-surface) px-3 py-2 text-sm" /><button type="button" onClick={() => setList(list.filter((_, position) => position !== index))} className="cursor-pointer rounded-lg border border-(--color-border) px-3 text-sm">Убрать</button></div>)}<button type="button" onClick={() => setList([...list, { field: '', values: '' }])} className="cursor-pointer text-sm text-(--color-action)">+ Добавить условие</button></fieldset>;
  const save = (event: FormEvent) => {
    event.preventDefault(); setPending(true); setError('');
    router.post(`/seller/scenario/techniques/${technique.id}`, {
      version, name, idea, examples: examples.split('\n').map((line) => line.trim()).filter(Boolean), max_uses_per_dialog: maxUses,
      enabled, is_base: isBase, when: when.filter((row) => row.field || row.values), not_when: notWhen.filter((row) => row.field || row.values),
    }, { preserveScroll: true, onSuccess: () => onDone('Черновик сохранён. Опубликуйте его для применения.'), onError: (errors) => setError(Object.values(errors)[0] || 'Не удалось сохранить приём.'), onFinish: () => setPending(false) });
  };

  return <form onSubmit={save} className="rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)">
    <h2 className="text-xl font-semibold">Редактор приёма</h2><p className="mt-1 text-sm text-(--color-ink-muted)">ID: {technique.id}</p>
    {error && <p role="alert" className="mt-3 text-sm text-red-700">{error}</p>}
    <label className="mt-5 block text-sm font-semibold">Название<input value={name} onChange={(event) => setName(event.target.value)} maxLength={120} required className="mt-2 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3 py-2 font-normal" /></label>
    <label className="mt-4 block text-sm font-semibold">Суть для бота<textarea value={idea} onChange={(event) => setIdea(event.target.value)} maxLength={1000} required rows={3} className="mt-2 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3 py-2 font-normal" /></label>
    <label className="mt-4 block text-sm font-semibold">Примеры фраз, по одной на строке<textarea value={examples} onChange={(event) => setExamples(event.target.value)} rows={4} className="mt-2 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3 py-2 font-normal" /></label>
    <label className="mt-4 block text-sm font-semibold">Лимит на диалог<input type="number" min={1} max={10} value={maxUses} onChange={(event) => setMaxUses(Number(event.target.value))} className="mt-2 block w-24 rounded-lg border border-(--color-border) bg-(--color-surface) px-3 py-2 font-normal" /></label>
    <div className="mt-4 flex flex-wrap gap-5 text-sm"><label className="flex cursor-pointer items-center gap-2"><input type="checkbox" checked={enabled} onChange={(event) => setEnabled(event.target.checked)} />Включён</label><label className="flex cursor-pointer items-center gap-2"><input type="checkbox" checked={isBase} onChange={(event) => setIsBase(event.target.checked)} />Базовый приём</label></div>
    {conditions(when, setWhen, 'Когда уместен')}{conditions(notWhen, setNotWhen, 'Когда не уместен')}
    <details className="mt-5 text-sm"><summary className="cursor-pointer">Расширенные условия JSON (только чтение)</summary><pre className="mt-2 max-w-full overflow-x-auto rounded-lg bg-(--color-surface-muted) p-3 text-xs">{JSON.stringify({ when: technique.when, not_when: technique.not_when }, null, 2)}</pre></details>
    <button type="submit" disabled={pending} className="mt-5 cursor-pointer rounded-lg bg-(--color-action) px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{pending ? 'Сохраняем…' : 'Сохранить в черновик'}</button>
  </form>;
}

export default function Index({ techniques, release, version, draft }: Props) {
  const [selected, setSelected] = useState(techniques[0]?.id ?? '');
  const [pending, setPending] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const active = techniques.find((item) => item.id === selected) ?? techniques[0];
  const action = (url: string, data: Record<string, string | number>, success: string) => {
    setPending(true); setError('');
    router.post(url, data, { preserveScroll: true, onSuccess: () => setMessage(success), onError: (errors) => setError(Object.values(errors)[0] || 'Не удалось выполнить действие.'), onFinish: () => setPending(false) });
  };
  return <CabinetLayout breadcrumbs={[{ label: 'Продавец', href: '/seller' }, { label: 'Приёмы продаж' }]}>
    <Head title="Приёмы продаж" />
    <div className="mb-6"><h1 className="text-3xl font-bold">Приёмы продаж</h1><p className="mt-2 text-sm text-(--color-ink-muted)">Условия применения, примеры и результат за 30 дней</p></div>
    {message && <p role="status" className="mb-4 rounded-lg bg-(--color-surface-muted) p-3 text-sm">{message}</p>}{error && <p role="alert" className="mb-4 rounded-lg border border-red-300 p-3 text-sm text-red-700">{error}</p>}
    <div className="mb-5 flex flex-wrap items-center gap-3 text-sm"><span className="rounded-full bg-(--color-surface-muted) px-3 py-2">{draft ? 'Черновик' : 'Опубликованная версия'}</span>{draft && release && <button type="button" disabled={pending} onClick={() => action('/seller/scenario/techniques/publish', { release, version }, 'Приёмы опубликованы.')} className="cursor-pointer rounded-lg bg-(--color-action) px-4 py-2 font-semibold text-white disabled:opacity-50">Опубликовать черновик</button>}{techniques.length > 0 && <button type="button" disabled={pending} onClick={() => action('/seller/scenario/techniques/quiet', { version }, 'Небазовые приёмы выключены в черновике.')} className="cursor-pointer rounded-lg border border-(--color-border) px-4 py-2 disabled:opacity-50">Выключить всё, кроме базовых</button>}</div>
    {techniques.length === 0 ? <div className="rounded-2xl border border-(--color-border) bg-(--color-surface) p-6 text-sm text-(--color-ink-muted)">Приёмов пока нет. Сначала опубликуйте базу знаний для текущего бренда.</div> : <div className="grid min-w-0 gap-5 lg:grid-cols-[300px_minmax(0,1fr)]">
      <nav aria-label="Список приёмов" className="space-y-2">{techniques.map((item) => <button key={item.id} type="button" onClick={() => { setSelected(item.id); setError(''); setMessage(''); }} aria-current={active?.id === item.id ? 'page' : undefined} className={`w-full cursor-pointer rounded-xl border p-4 text-left text-sm ${active?.id === item.id ? 'border-(--color-action) bg-(--color-surface-muted)' : 'border-(--color-border) bg-(--color-surface)'}`}><span className="block font-semibold">{item.name}</span><span className="mt-1 block text-(--color-ink-muted)">{item.enabled ? 'Включён' : 'Выключен'}{item.is_base ? ' · базовый' : ''} · {item.uses} сработок</span></button>)}</nav>
      <div className="min-w-0">{active && <><div className="mb-5 grid gap-3 sm:grid-cols-3"><div className="rounded-xl border border-(--color-border) bg-(--color-surface) p-4 text-sm">Сработок за 30 дней<strong className="mt-1 block text-xl">{active.uses}</strong></div><div className="rounded-xl border border-(--color-border) bg-(--color-surface) p-4 text-sm">Конверсия с приёмом<strong className="mt-1 block text-xl">{percent(active.conversion_with)}</strong></div><div className="rounded-xl border border-(--color-border) bg-(--color-surface) p-4 text-sm">Без приёма<strong className="mt-1 block text-xl">{percent(active.conversion_without)}</strong></div></div><Editor key={`${active.id}:${version}`} technique={active} version={version} onDone={setMessage} /></>}</div>
    </div>}
  </CabinetLayout>;
}
