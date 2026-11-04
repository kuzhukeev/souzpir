import { Link } from '@inertiajs/react';

type Suggestion = { id: string; conversation_id: string; name: string; channel: string; created_at: string; input: string; suggestion: string };

export default function ReadySuggestions({ suggestions }: { suggestions: Suggestion[] }) {
    return <section className="mt-6" aria-labelledby="suggestions-title">
        <h2 id="suggestions-title" className="text-xl font-semibold">Готовые подсказки ({suggestions.length})</h2>
        <p className="mt-2 text-sm text-(--color-ink-muted)">Отправка и отклонение ответов пока недоступны.</p>
        {suggestions.length === 0 ? <p className="mt-4 rounded-xl bg-(--color-surface) p-5">Подсказок нет.</p> :
            <ul className="mt-4 space-y-4">{suggestions.map(item => <li key={item.id} className="rounded-xl border border-(--color-border) bg-(--color-surface) p-5">
                <div className="flex flex-wrap items-center justify-between gap-3"><h3 className="font-semibold">{item.name}</h3><span className="text-sm">{item.channel} · {new Date(item.created_at).toLocaleString('ru-RU')}</span></div>
                <p className="mt-4 text-sm font-semibold">Последняя реплика клиента</p><p className="mt-1 whitespace-pre-wrap text-sm">{item.input}</p>
                <p className="mt-4 text-sm font-semibold">Предложенный ответ</p><p className="mt-1 whitespace-pre-wrap text-sm">{item.suggestion}</p>
                <Link className="mt-4 inline-block cursor-pointer text-sm text-(--color-link)" href={`/seller/dialogs/${item.conversation_id}`}>Открыть диалог</Link>
            </li>)}</ul>}
    </section>;
}
