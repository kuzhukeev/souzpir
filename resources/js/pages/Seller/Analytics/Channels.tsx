type Metric = {channel:string;dialogs:number;contacts:number;conversion:number|null;average_rating:number|null;planned:boolean};
type Page = {page:string;opens:number;dialogs:number;contacts:number;utm:string|null};
const names:Record<string,string>={widget:'Сайт',telegram:'Telegram',whatsapp:'WhatsApp',api:'API',unknown:'Не определён'};

export default function Channels({metrics,pages}:{metrics:Metric[];pages:Page[]}) {
  return <section id="channels" className="mt-4 rounded-xl border border-(--color-border) bg-(--color-surface) p-5" aria-label="Каналы и источники">
    <h2 className="text-xl font-bold">Каналы и источники</h2>
    <p className="mt-2 text-sm text-(--color-ink-muted)">Конверсия — доля диалогов с сохранённым контактом. Оценка — средняя последняя оценка диалога.</p>
    <div className="mt-4 overflow-x-auto"><table className="w-full min-w-[620px] text-left text-sm"><thead><tr className="border-b border-(--color-border)"><th className="p-3">Канал</th><th className="p-3">Диалоги</th><th className="p-3">Контакты</th><th className="p-3">Конверсия</th><th className="p-3">Средняя оценка</th></tr></thead><tbody>{metrics.map(item=><tr key={item.channel} className="border-b border-(--color-border) last:border-0"><th className="p-3">{names[item.channel]??item.channel}{item.planned&&<span className="ml-2 text-(--color-ink-muted)">Скоро</span>}</th><td className="p-3">{item.planned?'—':item.dialogs}</td><td className="p-3">{item.planned?'—':item.contacts}</td><td className="p-3">{item.planned?'—':item.conversion===null?'—':`${item.conversion}%`}</td><td className="p-3">{item.planned?'—':item.average_rating??'—'}</td></tr>)}</tbody></table></div>
    <h3 className="mt-6 text-lg font-semibold">Страницы сайта</h3>
    {pages.length?<div className="mt-3 overflow-x-auto"><table className="w-full min-w-[680px] text-left text-sm"><thead><tr><th className="p-3">Страница</th><th className="p-3">Открытий виджета</th><th className="p-3">Диалогов</th><th className="p-3">Контактов</th><th className="p-3">UTM-метки</th></tr></thead><tbody>{pages.map(item=><tr key={item.page} className="border-t border-(--color-border)"><th className="p-3 break-all">{item.page}</th><td className="p-3">{item.opens}</td><td className="p-3">{item.dialogs}</td><td className="p-3">{item.contacts}</td><td className="p-3">{item.utm??'—'}</td></tr>)}</tbody></table></div>:<p className="mt-3 text-sm">Данных по страницам пока нет: события открытия виджета и адрес страницы не сохраняются.</p>}
  </section>;
}
