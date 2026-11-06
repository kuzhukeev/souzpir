type Manager = {name: string; workload?: Record<string, number>};
const colors = ['#2563eb', '#db2777', '#059669', '#d97706', '#7c3aed', '#0e7490'];

export default function WorkloadChart({managers}: {managers: Manager[]}) {
  const days = Array.from(new Set(managers.flatMap(manager => Object.keys(manager.workload ?? {})))).sort();
  if (!days.length) return <p className="mt-2 text-sm">Нет данных о принятых диалогах.</p>;
  const max = Math.max(1, ...managers.flatMap(manager => Object.values(manager.workload ?? {})));
  return <figure aria-label="Загрузка менеджеров по дням" className="mt-3"><div className="flex flex-wrap gap-x-4 gap-y-2 text-sm">{managers.map((manager,index)=><span key={index} className="inline-flex items-center gap-2"><span aria-hidden="true" className="size-3 rounded-sm" style={{backgroundColor:colors[index % colors.length]}}/>{manager.name}</span>)}</div><div className="mt-4 overflow-x-auto"><div className="flex min-w-max items-end gap-5 border-b border-(--color-border) pb-2">{days.map(day=><div key={day} className="min-w-20 text-center"><div className="flex h-40 items-end justify-center gap-1">{managers.map((manager,index)=>{const count=manager.workload?.[day] ?? 0;return <div key={index} role="img" aria-label={`${day}, ${manager.name}: ${count}`} title={`${manager.name}: ${count}`} className="w-5 rounded-t" style={{height:`${count / max * 100}%`,backgroundColor:colors[index % colors.length]}}/>;})}</div><span className="mt-2 block text-sm">{day}</span></div>)}</div></div><figcaption className="mt-2 text-sm text-(--color-ink-muted)">Число принятых диалогов по дате принятия.</figcaption></figure>;
}
