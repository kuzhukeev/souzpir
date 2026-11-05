import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Run = { id: string; persona_id: string; status: string; result: string; average: number | null; comment: string | null; turn_count: number; turns: { client: string; agent: string }[]; created_at: string };
type Scenario = { id: string; name: string; type: string; max_turns: number; behavior: string; first_message: string; expect: string[]; editable: boolean; who: string; want: string; goal: string; expected_result: string; in_set: boolean; last_result: Run | null };
type Props = { scenarios: Scenario[]; runs: Run[]; status: string | null; runAllAvailable: boolean };

const types: Record<string, string> = { newbie: 'Начинающий', experienced: 'Опытный', for_self: 'Для себя', prices_only: 'Только цены', troll: 'Вне темы', edge: 'Особый случай' };
const expects: Record<string, string> = { full_card: 'Полная карточка', partial_or_full_contact: 'Получен контакт', no_prohibition_violation: 'Не нарушены запреты', no_deferred_promise: 'Не обещал лишнего', no_argument: 'Не спорить с клиентом', one_question_per_turn: 'Не более одного вопроса', score_at_least_4: 'Оценка ≥ 4' };
const outcomes: Record<string, string> = { contact: 'Контакт получен', handoff: 'Передано менеджеру', refusal: 'Корректный отказ' };
const results: Record<string, string> = { passed: 'Пройден', failed: 'Не пройден', pending: 'Ожидает оценки', test_baseline: 'Тестовая оценка' };
const field = 'mt-1 block min-h-11 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3 py-2 text-sm text-(--color-ink)';
const button = 'min-h-11 cursor-pointer rounded-lg px-4 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50';

function Editor({ scenario, runs, onClose }: { scenario: Scenario | null; runs: Run[]; onClose: () => void }) {
    const form = useForm({ id: scenario?.id ?? null, name: scenario?.name ?? '', type: scenario?.type ?? 'newbie', who: scenario?.who ?? '', want: scenario?.want ?? '', goal: scenario?.goal ?? '', behavior: scenario?.behavior ?? '', first_message: scenario?.first_message ?? '', max_turns: scenario?.max_turns ?? 12, expect: scenario?.expect ?? [] as string[], expected_result: scenario?.expected_result ?? 'contact' });
    const history = runs.filter((run) => run.persona_id === scenario?.id);
    const otherExpect = form.data.expect.filter((key) => !(key in expects));

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose(); };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    return <div className="fixed inset-0 z-50 flex justify-end bg-black/40" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
        <form role="dialog" aria-modal="true" aria-labelledby="scenario-editor-title" className="flex h-full w-full max-w-[660px] flex-col bg-(--color-surface) text-(--color-ink) shadow-xl" onSubmit={(event) => { event.preventDefault(); form.post('/seller/quality/scenarios', { preserveScroll: true, onSuccess: onClose }); }}>
            <header className="flex items-center justify-between border-b border-(--color-border) px-6 py-4">
                <div><h2 id="scenario-editor-title" className="text-xl font-bold">{scenario ? 'Редактировать сценарий' : 'Новый сценарий'}</h2><p className="text-sm text-(--color-ink-muted)">Настройка контрольного клиента и результата</p></div>
                <button type="button" onClick={onClose} aria-label="Закрыть редактор" className="min-h-11 min-w-11 cursor-pointer rounded-lg text-xl">×</button>
            </header>
            <div className="flex-1 space-y-5 overflow-y-auto px-6 py-5 text-sm">
                <label className="block font-medium">Название<input required maxLength={200} className={field} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></label>
                <label className="block font-medium">Тип клиента<select className={`${field} cursor-pointer`} value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>{Object.entries(types).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
                <div className="grid gap-4 sm:grid-cols-2">
                    <label className="block font-medium">Кто<textarea maxLength={200} rows={2} className={field} value={form.data.who} onChange={(e) => form.setData('who', e.target.value)} /></label>
                    <label className="block font-medium">Чего хочет<textarea maxLength={500} rows={2} className={field} value={form.data.want} onChange={(e) => form.setData('want', e.target.value)} /></label>
                </div>
                <label className="block font-medium">Цель сценария<textarea maxLength={500} rows={2} className={field} value={form.data.goal} onChange={(e) => form.setData('goal', e.target.value)} /></label>
                <label className="block font-medium">Поведение клиента<textarea required maxLength={2000} rows={3} className={field} value={form.data.behavior} onChange={(e) => form.setData('behavior', e.target.value)} /></label>
                <label className="block font-medium">Первое сообщение<textarea maxLength={1000} rows={2} className={field} value={form.data.first_message} onChange={(e) => form.setData('first_message', e.target.value)} /></label>
                <label className="block font-medium">Максимум ходов<input required min={1} max={30} type="number" className={field} value={form.data.max_turns} onChange={(e) => form.setData('max_turns', Number(e.target.value))} /></label>
                <fieldset><legend className="font-semibold">Критерии оценки</legend><div className="mt-2 grid gap-2 sm:grid-cols-2">{Object.entries(expects).map(([key, label]) => <label key={key} className="flex min-h-8 cursor-pointer items-center gap-2"><input type="checkbox" checked={form.data.expect.includes(key)} onChange={(e) => form.setData('expect', e.target.checked ? [...form.data.expect, key] : form.data.expect.filter((item) => item !== key))} />{label}</label>)}</div>{otherExpect.length > 0 && <p className="mt-2 text-(--color-ink-muted)">Дополнительные критерии пресета: {otherExpect.join(', ')}</p>}</fieldset>
                <label className="block font-semibold">Ожидаемый итог<select className={`${field} cursor-pointer font-normal`} value={form.data.expected_result} onChange={(e) => form.setData('expected_result', e.target.value)}>{Object.entries(outcomes).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
                <section aria-label="История прогонов"><h3 className="font-semibold">История прогонов</h3>{history.length === 0 ? <p className="mt-2 text-(--color-ink-muted)">Прогонов пока нет.</p> : <ul className="mt-2 space-y-2">{history.map((run) => <li key={run.id} className="rounded-lg border border-(--color-border) p-3"><div className="flex justify-between gap-3"><span>{new Date(run.created_at).toLocaleString('ru-RU')}</span><strong>{results[run.result] ?? run.result}{run.average !== null ? ` · ${run.average}/5` : ''}</strong></div>{run.comment && <p className="mt-1 text-(--color-ink-muted)">{run.comment}</p>}<details className="mt-2"><summary className="cursor-pointer text-(--color-link)">Диалог, {run.turn_count} ходов</summary>{run.turns.map((turn, index) => <div key={index} className="mt-2"><p>Клиент: {turn.client}</p><p>Продавец: {turn.agent}</p></div>)}</details></li>)}</ul>}</section>
                {Object.keys(form.errors).length > 0 && <p role="alert" className="text-(--color-danger-ink)">{Object.values(form.errors).join(' ')}</p>}
            </div>
            <footer className="flex justify-end gap-3 border-t border-(--color-border) px-6 py-4"><button type="button" onClick={onClose} className={`${button} border border-(--color-border)`}>Отмена</button><button disabled={form.processing} className={`${button} bg-(--color-link) text-white`}>{form.processing ? 'Сохранение…' : 'Сохранить'}</button></footer>
        </form>
    </div>;
}

export default function Index({ scenarios, runs, status, runAllAvailable }: Props) {
    const [editing, setEditing] = useState<Scenario | null | undefined>(undefined);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const copy = (scenario: Scenario) => { setBusy(scenario.id); setError(null); router.post(`/seller/quality/scenarios/${scenario.id}/copy`, {}, { preserveScroll: true, onError: () => setError('Не удалось скопировать сценарий.'), onFinish: () => setBusy(null) }); };
    const runAll = () => { setBusy('all'); setError(null); router.post('/seller/quality/scenarios/run-all', {}, { preserveScroll: true, onError: () => setError('Не удалось запустить набор сценариев.'), onFinish: () => setBusy(null) }); };
    const toggle = (scenario: Scenario) => { setBusy(scenario.id); setError(null); router.patch(`/seller/quality/scenarios/${scenario.id}/set`, { in_set: !scenario.in_set }, { preserveScroll: true, onError: () => setError('Не удалось обновить набор.'), onFinish: () => setBusy(null) }); };

    return <CabinetLayout breadcrumbs={[{ label: 'Продавец', href: '/seller' }, { label: 'Качество', href: '/seller/quality' }, { label: 'Сценарии' }]}><Head title="Контрольные сценарии" />
        <main className="mx-auto max-w-[1320px] text-(--color-ink)" data-private-profile><Link href="/seller/quality/runs" className="inline-block min-h-11 cursor-pointer text-sm text-(--color-link)">Прогоны →</Link>
            <div className="flex flex-wrap items-start justify-between gap-4"><div><h1 className="text-3xl font-bold">Контрольные сценарии</h1><p className="mt-2 text-sm text-(--color-ink-muted)">Проверка продавца на типичных ситуациях текущего бренда</p></div><button type="button" disabled={!runAllAvailable || busy !== null} onClick={runAll} title={runAllAvailable ? undefined : "Для запуска нужны сценарии в наборе, опубликованные знания и fake runtime"} className={`${button} border border-(--color-border)`}>{busy === 'all' ? 'Запуск…' : 'Запустить все'}</button></div>
            {!runAllAvailable && <p className="mt-3 text-sm text-(--color-ink-muted)">Для массового запуска добавьте сценарии в набор, опубликуйте знания и включите fake runtime.</p>}
            {status && <p role="status" className="mt-4 rounded-lg bg-(--color-success-soft) p-3 text-sm">{status}</p>}
            {error && <p role="alert" className="mt-4 rounded-lg border border-(--color-border) p-3 text-sm text-(--color-danger-ink)">{error}</p>}
            <section className="mt-6 overflow-hidden rounded-xl border border-(--color-border) bg-(--color-surface)">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-(--color-border) px-5 py-4"><h2 className="text-lg font-bold">Сценарии <span className="font-normal text-(--color-ink-muted)">({scenarios.length})</span></h2><button type="button" onClick={() => setEditing(null)} className={`${button} bg-(--color-link) text-white`}>+ Добавить сценарий</button></div>
                {scenarios.length === 0 ? <p className="p-8 text-center text-sm text-(--color-ink-muted)">Сценариев пока нет. Добавьте первый контрольный сценарий.</p> : <div className="overflow-x-auto"><table className="w-full min-w-[920px] border-collapse text-left text-sm"><thead className="bg-(--color-surface-muted) text-(--color-ink-muted)"><tr><th className="px-5 py-3 font-semibold">Сценарий</th><th className="px-4 py-3 font-semibold">Тип клиента</th><th className="px-4 py-3 font-semibold">Цель</th><th className="px-4 py-3 font-semibold">Ожидаемый итог</th><th className="px-4 py-3 font-semibold">Последний результат</th><th className="px-4 py-3 font-semibold">В наборе</th><th className="px-4 py-3 font-semibold">Действия</th></tr></thead><tbody>{scenarios.map((scenario) => <tr key={scenario.id} className="border-t border-(--color-border) align-top"><td className="px-5 py-4"><strong className="block">{scenario.name}</strong><span className="text-(--color-ink-muted)">{scenario.editable ? 'Свой сценарий' : 'Пресет'}</span></td><td className="px-4 py-4">{types[scenario.type] ?? scenario.type}</td><td className="max-w-[180px] px-4 py-4">{scenario.goal || scenario.want || '—'}</td><td className="px-4 py-4">{outcomes[scenario.expected_result] ?? '—'}</td><td className="px-4 py-4">{scenario.last_result ? <><span className="block font-semibold">{results[scenario.last_result.result] ?? scenario.last_result.result}</span><span className="text-(--color-ink-muted)">{new Date(scenario.last_result.created_at).toLocaleDateString('ru-RU')}</span></> : <span className="text-(--color-ink-muted)">Не запускался</span>}</td><td className="px-4 py-4"><label className="inline-flex cursor-pointer items-center gap-2"><input type="checkbox" aria-label={`В наборе: ${scenario.name}`} checked={scenario.in_set} disabled={busy === scenario.id} onChange={() => toggle(scenario)} /><span>{scenario.in_set ? 'Да' : 'Нет'}</span></label></td><td className="px-4 py-4"><div className="flex flex-wrap gap-3">{scenario.editable && <button type="button" onClick={() => setEditing(scenario)} className="min-h-8 cursor-pointer font-semibold text-(--color-link)">Изменить</button>}<button type="button" disabled={busy === scenario.id} onClick={() => copy(scenario)} className="min-h-8 cursor-pointer font-semibold text-(--color-link) disabled:opacity-50">{busy === scenario.id ? 'Подождите…' : 'Копировать'}</button></div></td></tr>)}</tbody></table></div>}
            </section>
            {editing !== undefined && <Editor key={editing?.id ?? 'new'} scenario={editing} runs={runs} onClose={() => setEditing(undefined)} />}
        </main>
    </CabinetLayout>;
}
