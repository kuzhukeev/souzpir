import {Link} from '@inertiajs/react';
import {useEffect,useState} from 'react';
import {profileContextIsStable,subscribeProfileContext} from '@/features/Shell/Profile/contextFence';
type Check = {stage?: string | null; readiness?: number | {lower?: number; upper?: number} | null; pages?: number | null};
export default function SiteCheck({check, current, brandId, website, canPoll}: {check?: Check; current: boolean; brandId:string; website:string|null; canPoll:boolean}) {
    const [live,setLive]=useState<Check|undefined>(check);
    const [error,setError]=useState(false);
    const [stale,setStale]=useState(false);
    useEffect(()=>{
        if(!current||!website||!canPoll)return;
        let active=true; let controller:AbortController|null=null;
        const clear=()=>{active=false;controller?.abort();setLive(undefined);setStale(true);};
        const unsubscribe=subscribeProfileContext(clear);
        const load=async()=>{
            if(!active||document.visibilityState!=='visible'||!profileContextIsStable())return;
            controller?.abort(); controller=new AbortController();
            try {const response=await fetch('/vision/site?quiz_status=1',{headers:{Accept:'application/json','X-Brand-Id':brandId},credentials:'same-origin',cache:'no-store',signal:controller.signal});
                if(!response.ok)throw new Error();
                const data=await response.json() as Check & {website:string|null};
                if(active&&profileContextIsStable()&&data.website===website){setLive(data);setError(false);}
            } catch {if(active&&!controller.signal.aborted)setError(true);}
        };
        void load();const timer=window.setInterval(()=>void load(),5000);
        return()=>{active=false;controller?.abort();window.clearInterval(timer);unsubscribe();};
    },[brandId,website,current,canPoll]);
    const shown=stale ? undefined : (live ?? check);
    const stage = current ? shown?.stage : null;
    const readiness = current ? shown?.readiness : null;
    const value = typeof readiness === 'number' ? String(readiness) : readiness && typeof readiness.lower === 'number' && typeof readiness.upper === 'number' ? (readiness.lower === readiness.upper ? String(readiness.lower) : `${readiness.lower}–${readiness.upper}`) : null;
    const status = ({queued:'Проверка ожидает очереди.', inspecting:'Проверяем сайт.', saving:'Сохраняем результат.', complete:'Проверка завершена.', failed:'Не удалось проверить сайт. Повторите попытку.'} as Record<string,string>)[stage ?? ''];
    return <section aria-labelledby="site-check-title" className="mt-4 rounded-lg border border-(--color-border) p-4 text-sm">
        <h2 id="site-check-title" className="font-semibold">Проверка сайта</h2>
        <p role="status" className="mt-2">{status ?? (value !== null ? 'Сохранён результат проверки.' : 'Для указанного сайта нет сохранённого результата проверки.')}</p>
        {value !== null && <p className="mt-2">Готовность к ИИ-поиску: {value} из 100{typeof shown?.pages === 'number' ? ` · Проверено страниц: ${shown.pages}` : ''}</p>}
        {error&&<p role="alert" className="mt-2 text-(--color-danger-ink)">Не удалось обновить результат. Повторим автоматически.</p>}
        <Link href="/vision/site" className="mt-2 inline-block cursor-pointer text-(--color-link) underline">Подробности в разделе «Сайт»</Link>
    </section>;
}
