import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Release = { id: string; status: string; version: number; published_at: string | null };
type Choice = { id: string; name: string | null; type?: string };
type Guard = { verdict: string; issues: string[]; warnings: string[] };
type Inspector = { intent: string; extraction_source?: string; response_source?: string; client?: string | null; channel?: string | null; slot_updates: Record<string, unknown>; previous_answer: string | null; goal: { kind: string; slot: string | null; facts: string[]; trigger: string | null; allowed_tools: string[] }; executed_tools: string[]; guard: Guard };
type Session = { release: string; client_id: string | null; channel_id: string | null; history: { question: string; answer: string }[]; state: { stage?: string; slots?: Record<string, unknown>; counters?: { turns?: number } }; inspector: Inspector };
type Props = { releases: Release[]; clients?: Choice[]; channels?: Choice[]; sandboxSession?: Session | null };
const field = 'mt-1 block min-h-11 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm';
const button = 'min-h-11 cursor-pointer rounded-lg bg-(--color-link) px-4 text-sm font-semibold text-white disabled:opacity-50';
const card = 'rounded-xl border border-(--color-border) bg-(--color-surface) p-5';
const show = (value: unknown) => value == null || value === '' ? 'Не указано' : Array.isArray(value) ? value.join(', ') : String(value);
export default function Index(props: Props) {
    const brand = usePage<{brand: {id: string} | null}>().props.brand?.id ?? '';
    return <Sandbox key={brand} {...props} />;
}
function Sandbox({ releases, clients = [], channels = [], sandboxSession = null }: Props) {
    const [release, setRelease] = useState(sandboxSession?.release ?? releases.find(item => item.status === 'draft')?.id ?? releases[0]?.id ?? '');
    const [client, setClient] = useState(sandboxSession?.client_id ?? '');
    const [channel, setChannel] = useState(sandboxSession?.channel_id ?? '');
    const [session, setSession] = useState<Session | null>(sandboxSession);
    const [question, setQuestion] = useState('');
    const [fresh, setFresh] = useState(false);
    const [replyBusy, setReplyBusy] = useState(false);
    const [replyError, setReplyError] = useState('');
    const [replyNotice, setReplyNotice] = useState('');
    const [text, setText] = useState('');
    const [result, setResult] = useState<Guard | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [scenarioStatus, setScenarioStatus] = useState('');
    const [scenarioBusy, setScenarioBusy] = useState(false);
    async function request(path: string, body: object) {
        const token = document.cookie.split('; ').find(item => item.startsWith('XSRF-TOKEN='));
        const response = await fetch(path, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token.slice(11)) } : {}) }, body: JSON.stringify(body) });
        if (!response.ok) throw new Error();
        return response.json();
    }
    async function ask(event: React.FormEvent) {
        event.preventDefault(); setReplyBusy(true); setReplyError(''); setReplyNotice('');
        try {
            const data = await request('/seller/knowledge/sandbox/reply', { simulation: true, release, text: question, client_id: client || null, channel_id: channel || null, start_new: fresh, history: session?.history.map(turn => turn.question) ?? [] });
            if (data.status !== 'completed') { setReplyNotice(data.status === 'limit' ? 'Достигнут предел в 10 ходов. Начните заново.' : 'Нет подходящей записи для этого хода или черновика.'); return; }
            const next: Session = Array.isArray(data.history) ? data as Session : { release, client_id: client || null, channel_id: channel || null, history: [...(session?.history ?? []), { question, answer: data.answer }], state: {}, inspector: { intent: 'unclear', slot_updates: {}, previous_answer: session?.history.at(-1)?.answer ?? null, goal: { kind: data.goal?.kind ?? 'answer_only', slot: data.goal?.slot ?? null, facts: [], trigger: null, allowed_tools: data.goal?.allowed_tools ?? [] }, executed_tools: [], guard: data.guard } };
            setSession(next); setQuestion(''); setFresh(false);
        } catch { setReplyError('Не удалось получить ответ. Проверьте сеть и попробуйте снова.'); } finally { setReplyBusy(false); }
    }
    async function reset() {
        setReplyBusy(true); setReplyError('');
        try { await request('/seller/knowledge/sandbox/reply', { simulation: true, reset: true, release, text: 'reset', client_id: client || null, channel_id: channel || null }); setSession(null); setQuestion(''); setFresh(false); setReplyNotice(''); }
        catch { setReplyError('Не удалось очистить тестовый диалог.'); } finally { setReplyBusy(false); }
    }
    function saveScenario() {
        if (!session?.history.length) return;
        setScenarioStatus(''); setScenarioBusy(true);
        const transcript = session.history.map(turn => `Клиент: ${turn.question}\nБот: ${turn.answer}`).join('\n\n');
        router.post('/seller/quality/scenarios', { name: 'Тест из песочницы', type: 'edge', behavior: transcript.slice(0, 2000), first_message: session.history[0].question.slice(0, 1000), max_turns: Math.min(session.history.length, 10), expect: [] }, {
            preserveScroll: true, onSuccess: () => setScenarioStatus('Сценарий сохранён. При запуске используется первый вопрос; история сохранена в описании.'),
            onError: () => setScenarioStatus('Не удалось сохранить сценарий.'), onNetworkError: () => { setScenarioStatus('Нет связи. Сценарий не сохранён.'); return false; },
            onHttpException: () => { setScenarioStatus('Не удалось сохранить сценарий.'); return false; }, onFinish: () => setScenarioBusy(false),
        });
    }
    async function check(event: React.FormEvent) {
        event.preventDefault(); setBusy(true); setError(''); setResult(null);
        try { setResult(await request('/seller/knowledge/sandbox/check', { release, text }) as Guard); }
        catch { setError('Не удалось проверить ответ. Проверьте текст и попробуйте ещё раз.'); } finally { setBusy(false); }
    }
    function changeContext(update: () => void) { update(); setSession(null); setFresh(true); setReplyError(''); setReplyNotice(''); setResult(null); setError(''); }
    const inspector = session?.inspector;
    return <CabinetLayout breadcrumbs={[{ label: 'Продавец', href: '/seller' }, { label: 'База знаний', href: '/seller/knowledge' }, { label: 'Песочница' }]}>
        <Head title="Песочница" /><main className="mx-auto max-w-[1400px]" data-private-profile>
        <h1 className="text-3xl font-bold">Песочница знаний</h1><p className="mt-2 text-sm text-(--color-ink-muted)">Тестовый диалог использует выбранную версию знаний и не отправляет сообщения клиенту.</p>
        {releases.length === 0 ? <p className="mt-4 text-sm">Версий знаний пока нет. <Link className="cursor-pointer text-(--color-link)" href="/seller/knowledge">Создать черновик</Link></p> : <>
        <div className="mt-5 grid gap-3 md:grid-cols-3"><label className="text-sm">Версия знаний<select aria-label="Версия знаний" disabled={busy || replyBusy} className={`${field} cursor-pointer`} value={release} onChange={event => changeContext(() => setRelease(event.target.value))}>{releases.map(item => <option key={item.id} value={item.id}>{item.status === 'draft' ? 'Черновик' : 'Публикация'} · версия {item.version}</option>)}</select></label>
        <label className="text-sm">Клиент<select aria-label="Клиент" disabled={replyBusy} className={`${field} cursor-pointer`} value={client} onChange={event => changeContext(() => setClient(event.target.value))}><option value="">Тестовый клиент</option>{clients.map(item => <option key={item.id} value={item.id}>{item.name || 'Без имени'}</option>)}</select></label>
        <label className="text-sm">Канал<select aria-label="Канал" disabled={replyBusy} className={`${field} cursor-pointer`} value={channel} onChange={event => changeContext(() => setChannel(event.target.value))}><option value="">Тестовый канал</option>{channels.map(item => <option key={item.id} value={item.id}>{item.name || item.type}</option>)}</select></label></div>
        <div className="mt-5 grid gap-4 lg:grid-cols-2"><div className="space-y-4"><section className={card}><h2 className="text-xl font-bold">Диалог с ботом</h2><p className="mt-2 text-sm text-(--color-ink-muted)">Локальный fake-режим: используются policy и Guard, инструменты и внешняя доставка не запускаются. Ответы и состояние сохраняются в сессии.</p>
        {session?.history.length ? <ol aria-label="История диалога" className="mt-4 space-y-2 text-sm">{session.history.map((turn, index) => <li key={index} className="rounded-lg bg-(--color-surface-muted) p-3"><p><strong>Клиент:</strong> {turn.question}</p><p className="mt-2"><strong>Бот:</strong> {turn.answer}</p></li>)}</ol> : <p className="mt-4 text-sm">Диалог пока пуст.</p>}
        <form className="mt-4 space-y-3" onSubmit={ask}><label className="block text-sm">Сообщение клиента<textarea required maxLength={4000} value={question} onChange={event => setQuestion(event.target.value)} className={`${field} min-h-24 p-3`} /></label><div className="flex flex-wrap gap-3"><button disabled={replyBusy || (session?.history.length ?? 0) >= 10 || !release} className={button}>{replyBusy ? 'Готовим ответ…' : 'Получить ответ'}</button><button type="button" disabled={replyBusy || !session} onClick={reset} className="min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm disabled:opacity-50">Сбросить диалог</button></div>{replyError && <p role="alert" className="text-sm text-(--color-danger-ink)">{replyError}</p>}{(session?.history.length || replyNotice) ? <div role="status" className={replyNotice ? 'text-sm' : 'sr-only'}>{session?.history.length ? <><p>{session.history.at(-1)?.answer}</p><p>{session.inspector.guard.verdict === 'ok' ? 'Guard: проверка пройдена' : 'Есть замечания'}</p></> : null}{replyNotice && <p>{replyNotice}</p>}</div> : null}</form>{session?.history.length ? <div className="mt-3"><button type="button" disabled={scenarioBusy} onClick={saveScenario} className="min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm disabled:opacity-50">{scenarioBusy ? 'Сохраняем…' : 'Сохранить как сценарий'}</button>{scenarioStatus && <p role="status" className="mt-2 text-sm">{scenarioStatus}</p>}</div> : null}</section>
        <section className={card}><h2 className="text-xl font-bold">Проверка ответа</h2><form className="mt-3 space-y-3" onSubmit={check}><label className="block text-sm">Текст ответа<textarea required maxLength={4000} className={`${field} min-h-24 p-3`} value={text} onChange={event => setText(event.target.value)} /></label><button disabled={busy || !release} className={button}>{busy ? 'Проверяем…' : 'Проверить'}</button>{error && <p role="alert" className="text-sm text-(--color-danger-ink)">{error}</p>}{result && <div role="status" className="text-sm"><strong>{result.verdict === 'ok' ? 'Проверка пройдена' : 'Есть замечания'}</strong>{result.issues.map((issue, index) => <p key={index}>{issue}</p>)}{result.warnings.map((warning, index) => <p key={index}>Предупреждение: {warning}</p>)}</div>}</form></section></div>
        <section className={card}><h2 className="text-xl font-bold">Инспектор хода</h2><details><summary className="mt-3 cursor-pointer text-sm font-semibold">Внутренности хода</summary>{!inspector ? <p className="mt-3 text-sm">Отправьте сообщение, чтобы увидеть состояние и проверку.</p> : <div className="mt-3 space-y-2 break-words text-sm"><p>Ход: {session?.history.length}</p><p>Клиент: {inspector.client || 'Тестовый'} · канал: {inspector.channel || 'Тестовый'}</p><p>Предыдущий ответ: {inspector.previous_answer || 'Нет'}</p><p>Распознанная тема: {inspector.intent} ({inspector.extraction_source === 'fixture' ? 'записанная фикстура' : 'без распознанных данных'})</p><p>Источник ответа: {inspector.response_source === 'fixture' ? 'записанная фикстура' : inspector.response_source === 'history' ? 'предыдущий ответ' : 'упрощённый тестовый ответ'}</p><p>Этап: {show(session?.state.stage)}</p><p>Цель: {inspector.goal.kind} · поле: {show(inspector.goal.slot)}</p><p>Слоты клиента: {Object.entries(session?.state.slots ?? {}).filter(([, value]) => value != null && value !== '' && (!Array.isArray(value) || value.length)).map(([key, value]) => `${key}: ${show(value)}`).join('; ') || 'Не указаны'}</p><p>Факты: {inspector.goal.facts.join(', ') || 'Не выбраны'} · <Link className="cursor-pointer text-(--color-link)" href="/seller/knowledge/facts">Открыть факты</Link></p><p>Условия выбранной версии · <Link className="cursor-pointer text-(--color-link)" href="/seller/knowledge/conditions">Открыть условия</Link></p><p>Триггер: {inspector.goal.trigger || 'Нет'}</p><p>Доступные инструменты: {inspector.goal.allowed_tools.join(', ') || 'Нет'}</p><p>Выполненные инструменты: {inspector.executed_tools.join(', ') || 'Нет (тестовый режим)'}</p><p>Guard: {inspector.guard.verdict === 'ok' ? 'Проверка пройдена' : 'Есть замечания'}</p>{inspector.guard.issues.map((issue, index) => <p key={index}>{issue}</p>)}</div>}</details></section></div></>}
        </main></CabinetLayout>;
}
