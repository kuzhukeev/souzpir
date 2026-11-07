import { useEffect, useState } from 'react';
import { profileContextIsStable } from '@/features/Shell/Profile/contextFence';

export default function MeasurementSubscription({ measurement, brandId, revision }: {measurement:string;brandId:string;revision:string}) {
 const [enabled,setEnabled] = useState(false);
 const [expires,setExpires] = useState<string|null>(null);
 const [busy,setBusy] = useState(true);
 const [error,setError] = useState('');
 const url = `/vision/runs/${measurement}/subscription`;
 const headers = {'X-Brand-Id':brandId,'X-Brand-Revision':revision};
 useEffect(() => {
  const controller = new AbortController();
  fetch(url,{headers:{'X-Brand-Id':brandId,'X-Brand-Revision':revision,Accept:'application/json'},credentials:'same-origin',signal:controller.signal}).then(response => {if (!response.ok) throw new Error(); return response.json();}).then(data => { if (profileContextIsStable()) {setEnabled(data.subscribed);setExpires(data.expires_at);} }).catch(() => {if (!controller.signal.aborted) setError('Не удалось загрузить подписку.');}).finally(() => {if (!controller.signal.aborted) setBusy(false);});
  return () => controller.abort();
 },[url,brandId,revision]);
 const toggle = async () => {
  if (!profileContextIsStable()) {setError('Контекст бренда изменился. Обновите страницу.');return;}
  setBusy(true);setError('');
  try {const token = document.cookie.split('; ').find(cookie=>cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);const response = await fetch(url,{method:'PUT',credentials:'same-origin',headers:{...headers,Accept:'application/json','Content-Type':'application/json',...(token?{'X-XSRF-TOKEN':decodeURIComponent(token)}:{})},body:JSON.stringify({enabled:!enabled})});if(!response.ok) throw new Error();const data = await response.json();if(profileContextIsStable()){setEnabled(data.subscribed);setExpires(data.expires_at);}}
  catch {setError('Не удалось сохранить подписку. Попробуйте ещё раз.');}
  finally {setBusy(false);}
 };
 return <div className="mt-4 rounded-lg bg-(--color-surface-muted) p-4 text-sm"><button type="button" role="switch" aria-checked={enabled} disabled={busy} onClick={toggle} className="min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-3 disabled:opacity-50">{enabled ? 'Уведомить о готовности: включено' : 'Уведомить о готовности: выключено'}</button><p className="mt-2 text-(--color-ink-muted)">Тестовый режим: реальное письмо не отправляется.</p>{enabled && expires && <p className="mt-2">Подписка действует до {new Date(expires).toLocaleString('ru-RU')}.</p>}{error && <p role="alert" className="mt-2 text-(--color-danger-ink)">{error}</p>}</div>;
}
