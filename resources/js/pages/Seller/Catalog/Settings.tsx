import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';
import { Switch } from '@/components/ui';
type Import={id:string;filename:string;status:string;item_count:number;error:string|null;created_at:string};
type SettingsData={low_stock:string;price_file_name:string|null};
export default function Settings({imports,settings,status,yml,priceOptions={prices_enabled:false,discounts_enabled:false}}:{imports:Import[];settings:SettingsData;status?:string|null;priceOptions?:{prices_enabled:boolean;discounts_enabled:boolean};yml:{url:string;interval_hours:number|null;last_run_at:string|null}|null}) {
 const price=useForm<{file:File|null}>({file:null});
 const [intervalHours,setIntervalHours]=useState(yml?.interval_hours ?? null as number|null);
 const [pricesEnabled,setPricesEnabled]=useState(priceOptions.prices_enabled);
 const [discountsEnabled,setDiscountsEnabled]=useState(priceOptions.discounts_enabled);
 const [sendError,setSendError]=useState('');
 return <CabinetLayout breadcrumbs={[{label:'Продавец',href:'/seller'},{label:'Каталог',href:'/seller/integrations/catalog'},{label:'Настройки каталога'}]}><Head title="Настройки каталога"/><main className="mx-auto max-w-[1100px]" data-private-profile>
 <h1 className="text-3xl font-bold">Настройки каталога</h1><p className="mt-2 text-sm text-(--color-ink-muted)">Управление параметрами обновления и отправки данных</p>
 {status&&<p role="status" className="mt-4 rounded-lg bg-(--color-success-soft) p-3 text-sm">{status}</p>}
 <section className="mt-6 rounded-xl border border-(--color-border) bg-(--color-surface) p-5">
  <h2 className="text-xl font-bold">Основные настройки</h2>
  <div className="mt-4 space-y-4">
   <label className="block max-w-xs text-sm font-semibold">Частота обновления
    <select disabled={!yml} value={intervalHours ?? ''} onChange={e=>{const value=e.target.value?Number(e.target.value):null;setIntervalHours(value);router.post('/seller/integrations/catalog/yml',{url:yml?.url ?? '',interval_hours:value},{preserveScroll:true});}} className="mt-2 block min-h-11 w-full cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3 disabled:cursor-not-allowed disabled:opacity-60">
     <option value="">Вручную</option><option value="1">Каждый час</option><option value="6">Каждые 6 часов</option><option value="24">Каждый день</option><option value="168">Каждую неделю</option>
    </select>
    {!yml&&<span className="mt-1 block text-sm font-normal text-(--color-ink-muted)">Доступно после подключения ссылки на YML на странице «Каталог».</span>}
   </label>
   <div>
    <Switch label="Бот может называть цены" checked={pricesEnabled} onChange={value=>{setPricesEnabled(value);router.post('/seller/integrations/stock',{catalog_options:true,prices_enabled:value,discounts_enabled:discountsEnabled},{preserveScroll:true});}} />
    {pricesEnabled&&<p role="note" className="mt-2 max-w-md rounded-lg bg-(--color-warning-soft) p-3 text-sm">Убедитесь, что цены актуальны перед включением.</p>}
   </div>
   <Switch label="Бот может называть скидки бренда" checked={discountsEnabled} onChange={value=>{setDiscountsEnabled(value);router.post('/seller/integrations/stock',{catalog_options:true,prices_enabled:pricesEnabled,discounts_enabled:value},{preserveScroll:true});}} />
   <div className="border-t border-(--color-border) pt-4">
    <p className="text-sm font-semibold">Прайс-файл для отправки</p>
    <p className="mt-1 text-sm">{settings.price_file_name ? `Сохранён: ${settings.price_file_name}` : 'Файл пока не загружен.'}</p>
    <form className="mt-3 flex flex-wrap items-end gap-3" onSubmit={e=>{e.preventDefault();price.post('/seller/integrations/catalog/price-file',{forceFormData:true});}}>
     <label className="text-sm">Файл<input required type="file" accept=".csv,.xlsx,.pdf" onChange={ev=>price.setData('file',ev.target.files?.[0] ?? null)} className="mt-2 block cursor-pointer text-sm" /></label>
     <button disabled={price.processing} className="min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm font-semibold text-(--color-link) disabled:opacity-50">Сохранить файл</button>
    </form>
    {price.errors.file&&<p role="alert" className="mt-2 text-sm text-(--color-danger-ink)">{price.errors.file}</p>}
    <form className="mt-3" onSubmit={e=>{e.preventDefault();setSendError('');router.post('/seller/integrations/catalog/price-file/send',{},{preserveScroll:true,onError:errors=>setSendError(errors.send ?? '')});}}>
     <button className="min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm font-semibold">Отправить себе</button>
    </form>
    {sendError&&<p role="alert" className="mt-2 text-sm text-(--color-danger-ink)">{sendError}</p>}
   </div>
  </div>
 </section>
 <section id="catalog-history" className="mt-6 rounded-xl border border-(--color-border) bg-(--color-surface) p-5">
  <h2 className="text-xl font-bold">История импортов</h2>
  {imports.length===0?<p className="mt-3 text-sm">Загрузок пока нет.</p>:<div className="mt-3 overflow-x-auto" tabIndex={0} role="region" aria-label="История импортов"><table className="w-full min-w-[600px] text-left text-sm"><thead><tr className="border-b border-(--color-border) text-(--color-ink-muted)">{['Дата','Источник','Статус','Ошибки'].map(x=><th key={x} className="py-3 pr-4 font-semibold">{x}</th>)}</tr></thead><tbody>{imports.map(i=><tr key={i.id} className="border-b border-(--color-border) last:border-0"><td className="py-3 pr-4">{new Date(i.created_at).toLocaleString('ru-RU',{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'})}</td><td className="py-3 pr-4 break-all">{i.filename==='YML'?'YML по ссылке':i.filename}</td><td className="py-3 pr-4">{i.status==='completed'?<span className="rounded-full bg-(--color-success-soft) px-3 py-1">Успешно · {i.item_count}</span>:<span className="rounded-full bg-(--color-danger-soft) px-3 py-1 text-(--color-danger-ink)">Ошибка</span>}</td><td className="py-3 pr-4">{i.error??'—'}</td></tr>)}</tbody></table></div>}
 </section>
 </main></CabinetLayout>;
}
