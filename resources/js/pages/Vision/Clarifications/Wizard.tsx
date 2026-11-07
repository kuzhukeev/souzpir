import { Head, Link, router } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import { useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Step = 'mentions' | 'presence' | 'facts' | 'done';
type Item = { key: string; value: string; excerpt: string | null; decision: string | null; correction: string | null; profile_url: string | null; add_alias: boolean; from_questionnaire?: boolean; questionnaire_url?: string | null };
type Props = { measurement: string; step: Step; available: boolean; items: Item[]; total: number; pending: number; status: string | null; recalculated: { before: number; after: number } | null; alias_available?: boolean };
const percent = (value: number) => `${value.toLocaleString('ru-RU')} %`;
const order: Step[] = ['mentions', 'presence', 'facts', 'done'];
const titles: Record<Step, string> = { mentions: 'Это про вас?', presence: 'Вы есть на этих площадках?', facts: 'Это ваши утверждения или ошибки нейросети?', done: 'Готово к отчёту' };
const options: Record<Exclude<Step, 'done'>, [string, string][]> = {
    mentions: [['yes', 'Да, это мы'], ['no', 'Нет, другая компания'], ['unknown', 'Не знаю']],
    presence: [['yes', 'Есть'], ['no', 'Нет'], ['unknown', 'Не знаю']],
    facts: [['correct', 'Верно'], ['incorrect', 'Неверно'], ['outdated', 'Устарело']],
};
const path = (measurement: string, step: Step) => `/vision/runs/${measurement}/clarify/${step}`;
const action = 'min-h-11 cursor-pointer rounded-lg px-4 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50';

function DecisionCard({ item, step, measurement, aliasAvailable }: { item: Item; step: Exclude<Step, 'done'>; measurement: string; aliasAvailable: boolean }) {
    const [decision, setDecision] = useState(item.decision ?? '');
    const [correction, setCorrection] = useState(item.correction ?? '');
    const [profileUrl, setProfileUrl] = useState(item.profile_url ?? item.questionnaire_url ?? '');
    const [saving, setSaving] = useState(false);
    const [addAlias, setAddAlias] = useState(false);
    const [error, setError] = useState('');
    const save = (value: string) => {
        setError('');
        router.post(path(measurement, step), { item_key: item.key, decision: value, correction, profile_url: profileUrl, apply_alias: step === 'mentions' && value === 'yes' && addAlias }, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onError: (errors) => setError(Object.values(errors)[0] ?? 'Не удалось сохранить ответ.'),
            onNetworkError: () => { setError('Нет связи с сервером. Попробуйте ещё раз.'); return false; },
            onHttpException: () => { setError('Не удалось сохранить ответ. Попробуйте ещё раз.'); return false; },
        });
    };

    const match = item.excerpt?.toLocaleLowerCase().indexOf(item.value.toLocaleLowerCase()) ?? -1;
    return <section className="rounded-xl border border-(--color-border) bg-(--color-surface) p-5 text-sm">
        <h2 className="text-lg font-bold">{item.value}</h2>
        {step === 'presence' && item.from_questionnaire && <p className="mt-2 text-sm text-(--color-ink-muted)">Из анкеты{item.questionnaire_url && <> · <a href={item.questionnaire_url} target="_blank" rel="noopener noreferrer" className="cursor-pointer text-(--color-link) underline">Открыть профиль</a></>}</p>}
        {item.excerpt && <p className="mt-2 rounded-lg bg-(--color-surface-muted) p-3 text-(--color-ink-muted)">Фрагмент ответа: {match < 0 ? item.excerpt : <>{item.excerpt.slice(0, match)}<mark className="rounded bg-(--color-warning-soft) text-(--color-warning-ink)">{item.excerpt.slice(match, match + item.value.length)}</mark>{item.excerpt.slice(match + item.value.length)}</>}</p>}
        <fieldset className="mt-4"><legend className="font-semibold">Ваш ответ</legend><div className="mt-2 flex flex-wrap gap-3">{options[step].map(([value, label]) => <label key={value} className="flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-(--color-border) px-3"><input type="radio" name={`decision-${item.key}`} checked={decision === value} onChange={() => setDecision(value)} />{label}</label>)}</div></fieldset>
        {step === 'mentions' && decision === 'yes' && <label className="mt-4 flex items-center gap-2"><input type="checkbox" checked={addAlias} disabled={!aliasAvailable} onChange={event => setAddAlias(event.target.checked)} />Добавить в профиль как вариант названия</label>}
        {step === 'mentions' && decision === 'yes' && !aliasAvailable && <p className="mt-2 text-(--color-ink-muted)">Сначала заполните профиль бренда.</p>}
        {step === 'facts' && <p className="mt-4 text-(--color-ink-muted)">Неверные и устаревшие факты появятся во внутреннем отчёте «Что о вас говорят» и помогут выбрать действия.</p>}
        {step === 'presence' && <label className="mt-4 block font-medium">Ссылка на профиль (если есть)<input type="url" value={profileUrl} onChange={(event) => setProfileUrl(event.target.value)} maxLength={1000} className="mt-1 block min-h-11 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm" placeholder="https://example.com/profile" /></label>}
        {step === 'facts' && (decision === 'incorrect' || decision === 'outdated') && <label className="mt-4 block font-medium">Как правильно<textarea required value={correction} onChange={(event) => setCorrection(event.target.value)} maxLength={1000} rows={3} className="mt-1 block w-full rounded-lg border border-(--color-border) bg-(--color-surface) p-3 text-sm" /></label>}
        {error && <p role="alert" className="mt-3 text-(--color-danger-ink)">{error}</p>}
        <div className="mt-4 flex flex-wrap gap-3"><button type="button" disabled={saving || !decision} onClick={() => save(decision)} className={`${action} bg-(--color-link) text-white`}>{saving ? 'Сохранение…' : 'Сохранить ответ'}</button><button type="button" disabled={saving} onClick={() => { setDecision('deferred'); save('deferred'); }} className={`${action} border border-(--color-border)`}>Решить позже</button></div>
        {item.decision && <p className="mt-3 text-(--color-ink-muted)">Сохранено: {item.decision === 'deferred' ? 'решить позже' : 'ответ записан'}</p>}
    </section>;
}

export default function Wizard({ measurement, step, available, items, total, pending, status, recalculated, alias_available = false }: Props) {
    const index = order.indexOf(step);
    const next = order[index + 1];
    const title = titles[step];
    return <CabinetLayout breadcrumbs={[{ label: 'Видение', href: '/vision' }, { label: 'Замеры', href: '/vision/runs' }, { label: 'Уточнения' }]}><Head title={title} />
        <main className="mx-auto max-w-[960px] text-(--color-ink)">
            <p className="text-sm text-(--color-ink-muted)">Уточнения после замера · шаг {index + 1} из 4</p>
            <h1 className="mt-2 text-3xl font-bold">{title}</h1>
            <div className="mt-5 flex gap-2" aria-label={`Прогресс: ${index + 1} из 4`}>{order.map((part, position) => <div key={part} className={`h-2 flex-1 rounded-full ${position <= index ? 'bg-(--color-link)' : 'bg-(--color-surface-muted)'}`} />)}</div>
            {status && <p role="status" className="mt-5 rounded-lg bg-(--color-success-soft) p-3 text-sm">{status}</p>}
            {!available ? <section className="mt-6 rounded-xl border border-(--color-border) bg-(--color-surface) p-6"><p>Уточнения станут доступны после подтверждения завершённого замера.</p><Link href="/vision/runs" className="mt-4 inline-flex min-h-11 cursor-pointer items-center text-(--color-link)">К замерам</Link></section> : step === 'done' ? <section className="mt-6 rounded-xl border border-(--color-border) bg-(--color-surface) p-8 text-center">
                {recalculated ? <>
                    <div className="mx-auto flex size-20 items-center justify-center rounded-full bg-(--color-success-soft) text-(--color-success-ink)" aria-hidden="true"><CircleCheck className="size-10" /></div>
                    <h2 className="mt-5 text-2xl font-bold">Спасибо. Отчёт пересчитан с учётом ваших уточнений</h2>
                    <div className="mx-auto mt-6 max-w-sm border-t border-(--color-border) pt-6">
                        <p className="text-sm font-semibold text-(--color-ink-muted)">Что изменилось</p>
                        <div className="mt-3 rounded-xl bg-(--color-success-soft) p-4">
                            <p className="text-lg font-bold">Видимость: {percent(recalculated.before)} <span aria-hidden="true">→</span> <span className="text-(--color-success-ink)">{percent(recalculated.after)}</span></p>
                            <p className="mt-1 text-sm text-(--color-ink-muted)">После уточнения упоминаний</p>
                        </div>
                    </div>
                </> : <>
                    <h2 className="text-xl font-bold">Ответы сохранены</h2>
                    <p className="mt-3 text-sm">Всего уточнений: {total}. Требуют ответа или оставлены на потом: {pending}.</p>
                </>}
                <div className="mt-6 flex flex-wrap items-center justify-center gap-4"><Link href={path(measurement, 'mentions')} className="inline-flex min-h-11 cursor-pointer items-center text-(--color-link)">Вернуться к уточнениям</Link><Link href="/vision/report" className={`${action} inline-flex items-center bg-(--color-link) text-white`}>Открыть отчёт</Link></div>
            </section> : <>
                <p className="mt-4 text-sm text-(--color-ink-muted)">{total - pending} из {total} уточнений решено. Ответы «Не знаю» и «Решить позже» можно изменить позже.</p>
                {items.length === 0 ? <section className="mt-6 rounded-xl border border-(--color-border) bg-(--color-surface) p-8 text-center text-sm text-(--color-ink-muted)">{step === 'mentions' ? 'Нет упоминаний для уточнения.' : step === 'presence' ? 'Нет площадок для уточнения.' : 'Нет фактов для уточнения.'}</section> : <div className="mt-6 space-y-4">{items.map((item) => <DecisionCard key={item.key} item={item} step={step} measurement={measurement} aliasAvailable={alias_available} />)}</div>}
                <div className="mt-6 flex flex-wrap items-center justify-between gap-3"><Link href="/vision/runs" className="inline-flex min-h-11 cursor-pointer items-center text-sm text-(--color-link)">К замерам</Link>{next && <Link href={path(measurement, next)} className={`${action} inline-flex items-center bg-(--color-link) text-white`}>Продолжить</Link>}</div>
            </>}
        </main>
    </CabinetLayout>;
}
