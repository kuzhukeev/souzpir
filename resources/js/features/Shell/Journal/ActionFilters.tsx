import { useState } from 'react';
import { Button, Input, Select } from '@/components/ui';
import { go, type Filters, type Option } from './model';

function moscowDay(offset:number):string {
 const today=new Intl.DateTimeFormat('en-CA',{timeZone:'Europe/Moscow',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
 const day=new Date(`${today}T00:00:00Z`);day.setUTCDate(day.getUTCDate()+offset);return day.toISOString().slice(0,10);
}
export default function ActionFilters({initial,options}:{initial:Filters;options:{key:keyof Filters;label:string;items:Option[]}[]}) {
 const [filters,setFilters]=useState(initial);const [period,setPeriod]=useState(()=>{if(!initial.from&&!initial.to)return 'all';if(initial.to===moscowDay(0)){for(const count of [1,7,30])if(initial.from===moscowDay(1-count))return String(count);}return 'custom';});
 const change=(next:Filters)=>{setFilters(next);if(!next.from||!next.to||next.from<=next.to)go('/journal',next);};
 const choose=(value:string)=>{setPeriod(value);if(value==='custom')return;if(value==='all'){change({...filters,from:'',to:''});return;}const count=Number(value);change({...filters,from:moscowDay(1-count),to:moscowDay(0)});};
 return <div className="mb-5 flex flex-wrap items-end gap-3 rounded-2xl bg-(--color-surface) p-5 shadow-(--shadow-card)"><p className="w-full text-sm text-(--color-ink-muted)">Время и даты указаны по Москве</p><Select label="Период" value={period} onValueChange={choose} className="min-w-40 flex-1" options={[{value:'all',label:'За всё время'},{value:'1',label:'Сегодня'},{value:'7',label:'7 дней'},{value:'30',label:'30 дней'},{value:'custom',label:'Свой диапазон'}]}/>{period==='custom'&&<><Input label="С даты" type="date" value={filters.from??''} onChange={e=>change({...filters,from:e.target.value})} className="w-40 max-w-full"/><Input label="По дату" type="date" value={filters.to??''} min={filters.from||undefined} onChange={e=>change({...filters,to:e.target.value})} className="w-40 max-w-full"/></>}{options.map(item=><Select key={item.key} label={item.label} value={filters[item.key]||'all'} onValueChange={value=>change({...filters,[item.key]:value==='all'?'':value})} className="min-w-40 flex-1" options={[{value:'all',label:'Все'},...item.items]}/>)}<Button variant="ghost" onClick={()=>{setPeriod('all');change({});}}>Сбросить</Button></div>;
}
