import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Persona = {
    name?: string; role?: string; intro?: string; tone?: string[]; tone_input?: string[]; form_bans?: string[];
    bot_disclosure?: 'always' | 'if_asked'; address?: 'vy' | 'ty'; anchors?: string[];
    stop_phrases?: string[]; hard_bans?: string[];
    length?: { target_words?: [number, number]; max_words?: number; max_questions?: number; max_sentences?: number };
};
type PhraseKey = 'error' | 'handoff' | 'out_of_hours' | 'no_answer' | 'do_not_disturb' | 'reminder' | 'goodbye';
const phraseLabels: Record<PhraseKey, string> = {
    error: 'При ошибке', handoff: 'Передача менеджеру', out_of_hours: 'Вне рабочего времени',
    no_answer: 'Нет ответа', do_not_disturb: 'Не беспокоить', reminder: 'Напоминание', goodbye: 'Прощание',
};
const presets = ['Деловой', 'Тёплый', 'Короткий', 'С юмором'];
const field = 'mt-1 block min-h-11 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm';
const button = 'min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm font-semibold text-(--color-link) disabled:opacity-50';
const lines = (value: string) => value.split('\n').map(line => line.trim()).filter(Boolean);

export default function Index({ draft, persona, phrases, quickReplies, status }: {
    draft: { id: string; version: number } | null; persona: Persona | null;
    phrases: Record<PhraseKey, string>; quickReplies: string[]; status: string | null;
}) {
    const [tab, setTab] = useState<'profile' | 'phrases'>('profile');
    const [example, setExample] = useState(0);
    const profile = useForm({
        version: draft?.version ?? 1, name: persona?.name ?? '', role: persona?.role ?? 'Консультант',
        intro: persona?.intro ?? '', bot_disclosure: persona?.bot_disclosure ?? 'if_asked',
        address: persona?.address ?? 'vy', tone: (persona?.tone_input ?? persona?.tone ?? []).join('\n'),
        anchors: (persona?.anchors ?? []).join('\n'), form_bans: (persona?.form_bans ?? []).join('\n'),
        stop_phrases: (persona?.stop_phrases ?? []).join('\n'), hard_bans: (persona?.hard_bans ?? []).join('\n'),
        target_min: persona?.length?.target_words?.[0] ?? 30, target_max: persona?.length?.target_words?.[1] ?? 70,
        max_words: persona?.length?.max_words ?? 90, max_questions: persona?.length?.max_questions ?? 1,
        max_sentences: persona?.length?.max_sentences ?? 3,
    });
    const service = useForm({ version: draft?.version ?? 1, phrases, quick_replies: quickReplies });
    const submitProfile = (event: React.FormEvent) => {
        event.preventDefault();
        profile.transform(data => ({ ...data, version: draft?.version ?? data.version,
            tone: lines(data.tone), anchors: lines(data.anchors), form_bans: lines(data.form_bans),
            stop_phrases: lines(data.stop_phrases), hard_bans: lines(data.hard_bans) }));
        profile.post('/seller/persona');
    };
    const submitService = (event: React.FormEvent) => {
        event.preventDefault();
        service.transform(data => ({ ...data, version: draft?.version ?? data.version,
            quick_replies: data.quick_replies.map(item => item.trim()).filter(Boolean) }));
        service.post('/seller/persona/phrases');
    };
    const togglePreset = (preset: string) => {
        const current = lines(profile.data.tone);
        if (!current.includes(preset) && current.filter(item => presets.includes(item)).length >= 2) return;
        profile.setData('tone', (current.includes(preset) ? current.filter(item => item !== preset) : [...current, preset]).join('\n'));
    };
    const moveReply = (index: number, offset: number) => {
        const next = [...service.data.quick_replies];
        const target = index + offset;
        if (target < 0 || target >= next.length) return;
        [next[index], next[target]] = [next[target], next[index]];
        service.setData('quick_replies', next);
    };
    const preview = example === 0
        ? `${profile.data.intro.trim() || `Здравствуйте! Меня зовут ${profile.data.name || 'продавец'}.`} Чем могу помочь?`
        : `${profile.data.address === 'ty' ? 'Расскажи' : 'Расскажите'}, что вас интересует, и я помогу разобраться.`;

    return <CabinetLayout breadcrumbs={[{ label: 'Продавец', href: '/seller' }, { label: 'Персонаж' }]}>
        <Head title="Персонаж и служебные фразы" />
        <main className="mx-auto max-w-[1100px]" data-private-profile>
            <h1 className="text-3xl font-bold">Персонаж продавца</h1>
            <p className="mt-2 text-sm text-(--color-ink-muted)">Настройки сохраняются в черновике базы знаний и применяются после публикации.</p>
            {status && <p role="status" className="mt-4 rounded-lg bg-(--color-success-soft) p-3 text-sm">{status}</p>}
            <div role="tablist" aria-label="Настройки персонажа" className="mt-6 flex flex-wrap gap-2 border-b border-(--color-border)">
                <button type="button" role="tab" aria-selected={tab === 'profile'} onClick={() => setTab('profile')} className={`${button} rounded-b-none ${tab === 'profile' ? 'border-b-2 font-bold' : ''}`}>Профиль и тон</button>
                <Link href="/seller/settings/greetings" className={`${button} inline-flex items-center`}>Приветствия</Link>
                <button type="button" role="tab" aria-selected={tab === 'phrases'} onClick={() => setTab('phrases')} className={`${button} rounded-b-none ${tab === 'phrases' ? 'border-b-2 font-bold' : ''}`}>Служебные фразы</button>
            </div>
            {!draft ? <p className="mt-6 text-sm">Сначала <Link className="cursor-pointer font-semibold text-(--color-link)" href="/seller/knowledge">создайте черновик базы знаний</Link>.</p> : tab === 'profile' ? <>
                <form onSubmit={submitProfile} className="mt-5 space-y-5 rounded-xl border border-(--color-border) bg-(--color-surface) p-5">
                    <p className="text-sm">Версия черновика: {draft.version}</p>
                    <div className="grid gap-4 sm:grid-cols-2"><label className="text-sm">Имя бота<input required maxLength={40} className={field} value={profile.data.name} onChange={event => profile.setData('name', event.target.value)} /></label><label className="text-sm">Должность<input required maxLength={100} className={field} value={profile.data.role} onChange={event => profile.setData('role', event.target.value)} /></label></div>
                    <label className="block text-sm">Как представляется<textarea maxLength={400} className={`${field} p-3`} value={profile.data.intro} onChange={event => profile.setData('intro', event.target.value)} /></label>
                    <div className="grid gap-4 sm:grid-cols-2"><label className="text-sm">Говорит ли, что он бот<select className={field} value={profile.data.bot_disclosure} onChange={event => profile.setData('bot_disclosure', event.target.value as 'always' | 'if_asked')}><option value="always">Сразу</option><option value="if_asked">Если спросят</option></select><span className="mt-1 block text-(--color-ink-muted)">Скрывать, что это бот, нельзя.</span></label><label className="text-sm">Обращение<select className={field} value={profile.data.address} onChange={event => profile.setData('address', event.target.value as 'vy' | 'ty')}><option value="vy">Вы</option><option value="ty">Ты</option></select></label></div>
                    <fieldset><legend className="text-sm font-semibold">Тон общения</legend><div className="mt-2 flex flex-wrap gap-2">{presets.map(preset => <button key={preset} type="button" aria-pressed={lines(profile.data.tone).includes(preset)} onClick={() => togglePreset(preset)} className={`${button} ${lines(profile.data.tone).includes(preset) ? 'bg-(--color-surface-muted)' : ''}`}>{preset}</button>)}</div><label className="mt-3 block text-sm">Дополнительно, по одной фразе в строке<textarea className={`${field} p-3`} value={profile.data.tone} onChange={event => profile.setData('tone', event.target.value)} /></label></fieldset>
                    <div className="grid gap-4 sm:grid-cols-2"><label className="text-sm">Предложений в ответе<select className={field} value={profile.data.max_sentences} onChange={event => profile.setData('max_sentences', Number(event.target.value))}><option value={2}>1–2</option><option value={3}>До 3</option><option value={4}>2–4</option><option value={6}>До 6</option></select></label><label className="mt-6 flex items-center gap-2 text-sm"><input type="checkbox" checked={profile.data.max_questions === 1} onChange={event => profile.setData('max_questions', event.target.checked ? 1 : 2)} /> Не более одного вопроса в реплике</label></div>
                    <div className="grid gap-3 sm:grid-cols-3"><label className="text-sm">Слов от<input type="number" min={1} max={300} className={field} value={profile.data.target_min} onChange={event => profile.setData('target_min', Number(event.target.value))} /></label><label className="text-sm">Слов до<input type="number" min={1} max={300} className={field} value={profile.data.target_max} onChange={event => profile.setData('target_max', Number(event.target.value))} /></label><label className="text-sm">Максимум слов<input type="number" min={20} max={300} className={field} value={profile.data.max_words} onChange={event => profile.setData('max_words', Number(event.target.value))} /></label></div>
                    <label className="block text-sm">Якоря персонажа (по одному на строку)<textarea className={`${field} p-3`} value={profile.data.anchors} onChange={event => profile.setData('anchors', event.target.value)} /></label>
                    <label className="block text-sm">Стоп-слова (по одному на строку)<textarea className={`${field} p-3`} value={profile.data.stop_phrases} onChange={event => profile.setData('stop_phrases', event.target.value)} /></label>
                    <label className="block text-sm">Формы, которых следует избегать (по одной на строку)<textarea className={`${field} p-3`} value={profile.data.form_bans} onChange={event => profile.setData('form_bans', event.target.value)} /></label>
                    <label className="block text-sm">Жёсткие запреты (по одному на строку)<textarea className={`${field} p-3`} value={profile.data.hard_bans} onChange={event => profile.setData('hard_bans', event.target.value)} /></label>
                    {Object.keys(profile.errors).length > 0 && <p role="alert" className="text-sm text-(--color-danger-ink)">{Object.values(profile.errors).join(' ')}</p>}
                    <button disabled={profile.processing} className="min-h-11 cursor-pointer rounded-lg bg-(--color-link) px-4 text-sm font-semibold text-white disabled:opacity-50">{profile.processing ? 'Сохраняем…' : 'Сохранить персонажа'}</button>
                </form>
                <section className="mt-5 rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><h2 className="text-xl font-bold">Живой пример</h2><p className="mt-3 rounded-lg bg-(--color-surface-muted) p-4 text-sm">{preview}</p><button type="button" className={`${button} mt-3`} onClick={() => setExample(current => (current + 1) % 2)}>Обновить пример</button><p className="mt-2 text-sm text-(--color-ink-muted)">Пример обновляется из заполненных полей без вызова ИИ.</p></section>
            </> : <>
                <form onSubmit={submitService} className="mt-5 space-y-5 rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><h2 className="text-xl font-bold">Служебные фразы</h2><p className="text-sm">Версия черновика: {draft.version}</p>
                    <div className="grid gap-4 sm:grid-cols-2">{(Object.keys(phraseLabels) as PhraseKey[]).map(key => <label key={key} className="block text-sm">{phraseLabels[key]}<textarea maxLength={500} className={`${field} p-3`} value={service.data.phrases[key]} onChange={event => service.setData('phrases', { ...service.data.phrases, [key]: event.target.value })} /><span className="mt-1 block text-(--color-ink-muted)">{service.data.phrases[key].length} / 500 символов · {lines(profile.data.stop_phrases).some(word => service.data.phrases[key].toLocaleLowerCase('ru-RU').includes(word.toLocaleLowerCase('ru-RU'))) ? 'Есть стоп-слово' : 'Стоп-слов нет'}</span></label>)}</div>
                    <section><h3 className="text-lg font-bold">Быстрые ответы менеджера</h3><p className="mt-1 text-sm text-(--color-ink-muted)">Шаблоны можно менять местами.</p><div className="mt-3 space-y-2">{service.data.quick_replies.length === 0 && <p className="text-sm">Шаблонов пока нет.</p>}{service.data.quick_replies.map((reply, index) => <div key={index} className="flex flex-wrap items-center gap-2"><input aria-label={`Шаблон ${index + 1}`} maxLength={300} className={`${field} mt-0 min-w-[220px] flex-1`} value={reply} onChange={event => service.setData('quick_replies', service.data.quick_replies.map((item, i) => i === index ? event.target.value : item))} /><button type="button" className={button} disabled={index === 0} onClick={() => moveReply(index, -1)} aria-label={`Поднять шаблон ${index + 1}`}>↑</button><button type="button" className={button} disabled={index === service.data.quick_replies.length - 1} onClick={() => moveReply(index, 1)} aria-label={`Опустить шаблон ${index + 1}`}>↓</button><button type="button" className={button} onClick={() => service.setData('quick_replies', service.data.quick_replies.filter((_, i) => i !== index))} aria-label={`Удалить шаблон ${index + 1}`}>×</button></div>)}</div><button type="button" disabled={service.data.quick_replies.length >= 20} className={`${button} mt-3`} onClick={() => service.setData('quick_replies', [...service.data.quick_replies, ''])}>Добавить шаблон</button></section>
                    {Object.keys(service.errors).length > 0 && <p role="alert" className="text-sm text-(--color-danger-ink)">{Object.values(service.errors).join(' ')}</p>}
                    <button disabled={service.processing} className="min-h-11 cursor-pointer rounded-lg bg-(--color-link) px-4 text-sm font-semibold text-white disabled:opacity-50">{service.processing ? 'Сохраняем…' : 'Сохранить фразы'}</button>
                </form>
            </>}
        </main>
    </CabinetLayout>;
}
