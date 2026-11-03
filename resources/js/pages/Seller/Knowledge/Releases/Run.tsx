import { Head, Link } from '@inertiajs/react';
import CabinetLayout from '@/layouts/CabinetLayout';
type Turn = {client: string; agent: string; meta?: {expectation_checks?: Record<string, boolean>}};
export default function Run({run}: {run: {id: string; turns: Turn[]; created_at: string}}) {
  return <CabinetLayout breadcrumbs={[{label:'Продавец',href:'/seller'},{label:'База знаний',href:'/seller/knowledge'},{label:'Прогон сценария'}]}><Head title="Прогон сценария"/><main className="space-y-4"><h1 className="text-3xl font-bold">Прогон сценария</h1><p className="text-sm">Тестовый ответ · {new Date(run.created_at).toLocaleString('ru-RU')}</p><Link href="/seller/knowledge" className="cursor-pointer text-(--color-link)">← К базе знаний</Link>{run.turns.map((turn,index)=><section key={index} className="space-y-3 rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><p>Клиент: {turn.client}</p><p>Продавец: {turn.agent}</p>{Object.entries(turn.meta?.expectation_checks ?? {}).map(([name,passed])=><p key={name}>{name}: {passed ? 'пройдено' : 'не подтверждено'}</p>)}</section>)}</main></CabinetLayout>;
}
