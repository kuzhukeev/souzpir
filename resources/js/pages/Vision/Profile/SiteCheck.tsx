import {Link} from '@inertiajs/react';
type Check = {stage?: string | null; readiness?: number | {lower?: number; upper?: number} | null; pages?: number | null};
export default function SiteCheck({check, current}: {check?: Check; current: boolean}) {
    const stage = current ? check?.stage : null;
    const readiness = current ? check?.readiness : null;
    const value = typeof readiness === 'number' ? String(readiness) : readiness && typeof readiness.lower === 'number' && typeof readiness.upper === 'number' ? (readiness.lower === readiness.upper ? String(readiness.lower) : `${readiness.lower}–${readiness.upper}`) : null;
    const status = ({queued:'Проверка ожидает очереди.', inspecting:'Проверяем сайт.', saving:'Сохраняем результат.', complete:'Проверка завершена.', failed:'Не удалось проверить сайт. Повторите попытку.'} as Record<string,string>)[stage ?? ''];
    return <section aria-labelledby="site-check-title" className="mt-4 rounded-lg border border-(--color-border) p-4 text-sm">
        <h2 id="site-check-title" className="font-semibold">Проверка сайта</h2>
        <p role="status" className="mt-2">{status ?? (value !== null ? 'Сохранён результат проверки.' : 'Для указанного сайта нет сохранённого результата проверки.')}</p>
        {value !== null && <p className="mt-2">Готовность к ИИ-поиску: {value} из 100{typeof check?.pages === 'number' ? ` · Проверено страниц: ${check.pages}` : ''}</p>}
        <Link href="/vision/site" className="mt-2 inline-block cursor-pointer text-(--color-link) underline">Подробности в разделе «Сайт»</Link>
    </section>;
}
