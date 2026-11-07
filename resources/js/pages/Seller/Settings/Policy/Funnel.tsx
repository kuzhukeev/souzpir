import {Fragment,useState} from 'react';
type Stage = {next?:string|null;when?:string};
type Policy = {client_fields?:{id:string;name:string;required:boolean;stage?:string|null}[];limits?:{max_consecutive_questions?:number};stages?:Record<string,Stage>;tools_by_stage?:Record<string,string[]>;triggers_by_stage?:Record<string,string[]>};
const outcomes=[{key:'handoff',text:'У менеджера',className:'bg-(--color-info-soft) text-(--color-info-ink)'},{key:'done',text:'Закрыт',className:'bg-(--color-success-soft) text-(--color-success-ink)'},{key:'lost',text:'Потерян',className:'bg-(--color-danger-soft) text-(--color-danger-ink)'},{key:'spam',text:'Спам',className:'bg-(--color-warning-soft) text-(--color-warning-ink)'}];
export default function Funnel({policy,label,loadError=false}:{policy:Policy|null;label:(key:string)=>string;loadError?:boolean}) {
 const stages=Object.entries(policy?.stages??{});
 const questionLimit=policy?.limits?.max_consecutive_questions??2;
 const [selected,setSelected]=useState<string|null>(null);
 const current=stages.find(([name])=>name===(selected??stages[0]?.[0]));
 return <section aria-labelledby="funnel-title" className="mt-4 rounded-xl border border-(--color-border) bg-(--color-surface) p-5">
  <h2 id="funnel-title" className="text-xl font-bold">Воронка</h2><p className="mt-2 text-sm text-(--color-ink-muted)">Переходы и условия опубликованной базы знаний доступны только для чтения.</p>
  <p className="mt-2 text-sm text-(--color-ink-muted)">Правило вопросов: не более одного вопроса в реплике и не более {questionLimit} реплик подряд с вопросом — следующая отвечает по делу без вопроса.</p>
  {!policy?<p role={loadError ? "alert" : undefined} className="mt-3 text-sm">{loadError ? "Не удалось загрузить воронку. Повторите попытку." : "Нет опубликованной базы знаний."}</p>:stages.length===0?<p className="mt-3 text-sm">Этапы не настроены.</p>:<>
   <div className="mt-4 flex flex-wrap items-baseline justify-between gap-2">
    <h3 className="font-semibold">Переходы и условия <span className="font-normal text-(--color-ink-muted)">· только чтение</span></h3>
    <span className="text-xs text-(--color-ink-muted)">v2 · настраивается командой EYON при внедрении</span>
   </div>
   <div tabIndex={0} role="group" aria-label="Схема стадий воронки" className="mt-3 overflow-x-auto rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)">
    <div className="flex min-w-max items-center gap-2 p-1">
     {stages.map(([name],index)=><Fragment key={name}>
      <button type="button" aria-pressed={(selected??stages[0][0])===name} onClick={()=>setSelected(name)} className={`min-h-11 shrink-0 cursor-pointer rounded-lg border px-3 py-2 text-sm font-semibold ${(selected??stages[0][0])===name?'border-(--color-link) ring-2 ring-(--color-link)':'border-(--color-border)'} bg-(--color-surface-muted)`}>{label(name)}</button>
      {index<stages.length-1&&<span aria-hidden="true" className="shrink-0 text-(--color-ink-muted)">→</span>}
     </Fragment>)}
     <span aria-hidden="true" className="shrink-0 text-(--color-ink-muted)">→</span>
     <div className="flex shrink-0 flex-col gap-1">{outcomes.map(outcome=><span key={outcome.key} className={`rounded-md px-2 py-1 text-xs font-semibold ${outcome.className}`}>{outcome.text}</span>)}</div>
    </div>
   </div>
   {current&&(()=>{
    const [name,stage]=current;
    const fields=(policy.client_fields??[]).filter(field=>field.required===true&&field.stage===name&&typeof field.name==='string'&&field.name.trim());const tools=[...new Set([...(policy.tools_by_stage?.always??[]),...(policy.tools_by_stage?.[name]??[])])];const triggers=policy.triggers_by_stage?.[name]??[];
    const fieldNames=fields.map(field=>field.name).join(', ');
    return <div className="mt-4 rounded-lg border border-(--color-border) bg-(--color-surface-muted) p-4 text-sm"><h3 className="font-semibold">Стадия: {label(name)}</h3><p className="mt-1 text-(--color-ink-muted)">{stage.next?`Далее: ${label(stage.next)}`:'Следующий этап не указан'}</p><dl className="mt-3 space-y-3"><div><dt className="font-semibold">Что бот пытается получить</dt><dd className="mt-1">{fieldNames?`Узнать: ${fieldNames}`:'На этом этапе нет обязательных полей'}</dd></div><div><dt className="font-semibold">Поля, закрывающие стадию</dt><dd className="mt-1">{fieldNames||'Не настроены'}</dd></div><div><dt className="font-semibold">Условие перехода</dt><dd className="mt-1">{stage.when||'Не указано'}</dd></div><div><dt className="font-semibold">Разрешённые действия</dt><dd className="mt-1 break-words">{tools.length?tools.join(', '):'Не указаны'}</dd></div><div><dt className="font-semibold">Приёмы</dt><dd className="mt-1 break-words">{triggers.length?triggers.join(', '):'Не указаны'}</dd></div></dl></div>;
   })()}
  </>}
 </section>;
}
