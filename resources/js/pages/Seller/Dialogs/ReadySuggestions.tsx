import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Suggestion = { id: string; conversation_id: string; name: string; channel: string; created_at: string; input: string; suggestion: string };

const primaryButton = 'min-h-11 cursor-pointer rounded-lg bg-(--color-action) px-4 text-sm font-semibold text-(--color-on-action) hover:bg-(--color-action-hover) disabled:opacity-50';
const secondaryButton = 'min-h-11 cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-4 text-sm disabled:opacity-50';
const pageButton = 'min-h-11 cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3 disabled:cursor-default disabled:opacity-50';

function csrfToken(): string {
    const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

export default function ReadySuggestions({ suggestions }: { suggestions: Suggestion[] }) {
    const [items, setItems] = useState(suggestions);
    const [previous, setPrevious] = useState(suggestions);
    if (suggestions !== previous) {
        setPrevious(suggestions);
        setItems(suggestions);
    }
    const [texts, setTexts] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState('');
    const [wide, setWide] = useState(() => typeof window !== 'undefined' && window.matchMedia('(min-width: 1024px)').matches);
    useEffect(() => {
        const query = window.matchMedia('(min-width: 1024px)');
        const change = (): void => setWide(query.matches);
        change();
        query.addEventListener('change', change);

        return () => query.removeEventListener('change', change);
    }, []);
    const pageSize = wide ? 2 : 1;
    const pages = Math.max(1, Math.ceil(items.length / pageSize));
    const [pageState, setPage] = useState(0);
    const page = Math.min(pageState, pages - 1);
    const start = page * pageSize;
    const visible = items.slice(start, start + pageSize);

    const act = async (item: Suggestion, action: 'send' | 'skip' | 'mark', text?: string): Promise<void> => {
        setBusy(item.id);
        setError('');
        try {
            const response = await fetch(`/seller/dialogs/${item.conversation_id}/suggestions/${item.id}/${action}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
                body: action === 'send' ? JSON.stringify({ text: text ?? item.suggestion }) : undefined,
            });
            if (!response.ok) throw new Error();
            setItems(previous => previous.filter(value => value.id !== item.id));
        } catch {
            setError('Не удалось выполнить действие. Попробуйте снова.');
        } finally {
            setBusy(null);
        }
    };

    return <section className="mt-6" aria-labelledby="suggestions-title">
        <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 id="suggestions-title" className="text-xl font-semibold">Готовые подсказки ({items.length})</h2>
            <span className="flex items-center gap-2 text-sm text-(--color-ink-muted)"><span className="size-2 rounded-full bg-(--color-success)" aria-hidden="true" />Обновляется автоматически</span>
        </div>
        <p className="mt-2 text-sm text-(--color-ink-muted)">Проверьте ответы перед отправкой клиентам.</p>
        {error && <p role="alert" className="mt-3 text-sm text-(--color-danger)">{error}</p>}
        {items.length === 0 ? <p className="mt-4 rounded-xl bg-(--color-surface) p-5">Подсказок нет. Все клиенты получили ответ.</p> :
            <ul className="mt-4 space-y-4">{visible.map(item => {
                const text = texts[item.id] ?? item.suggestion;
                const itemBusy = busy === item.id;

                return <li key={item.id} className="rounded-xl border border-(--color-border) bg-(--color-surface) p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3"><h3 className="font-semibold">{item.name}</h3><span className="text-sm">{item.channel} · {new Date(item.created_at).toLocaleString('ru-RU')}</span></div>
                    <p className="mt-4 text-sm font-semibold">Последняя реплика клиента</p><p className="mt-1 whitespace-pre-wrap text-sm">{item.input}</p>
                    <label className="mt-4 block text-sm font-semibold" htmlFor={`suggestion-${item.id}`}>Предложенный ответ</label>
                    <textarea id={`suggestion-${item.id}`} className="mt-1 min-h-24 w-full rounded-lg border border-(--color-border) bg-(--color-background) p-3 text-sm"
                        value={text} disabled={itemBusy} onChange={event => setTexts(previous => ({ ...previous, [item.id]: event.target.value }))} />
                    <div className="mt-4 flex flex-wrap gap-3">
                        <button type="button" className={primaryButton} disabled={itemBusy} onClick={() => void act(item, 'send', item.suggestion)}>{itemBusy ? 'Отправляем…' : 'Отправить'}</button>
                        <button type="button" className={secondaryButton} disabled={itemBusy || text.trim() === ''} onClick={() => void act(item, 'send', text)}>Отправить с правкой</button>
                        <button type="button" className={secondaryButton} disabled={itemBusy} onClick={() => void act(item, 'skip')}>Пропустить</button>
                        <button type="button" className={secondaryButton} disabled={itemBusy} onClick={() => void act(item, 'mark')}>Пометить</button>
                    </div>
                    <Link className="mt-4 inline-block cursor-pointer text-sm text-(--color-link)" href={`/seller/dialogs/${item.conversation_id}`}>Открыть диалог</Link>
                </li>;
            })}</ul>}
        {items.length > 0 && <nav aria-label="Страницы подсказок" className="mt-5 flex items-center gap-3">
            <button type="button" className={pageButton} aria-label="Предыдущая подсказка" disabled={page === 0} onClick={() => setPage(page - 1)}>‹</button>
            <span>{pageSize === 1 ? `${start + 1} из ${items.length}` : `${start + 1}–${Math.min(start + pageSize, items.length)} из ${items.length}`}</span>
            <button type="button" className={pageButton} aria-label="Следующая подсказка" disabled={page >= pages - 1} onClick={() => setPage(page + 1)}>›</button>
        </nav>}
    </section>;
}
