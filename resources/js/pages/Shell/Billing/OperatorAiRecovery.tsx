import { Head } from '@inertiajs/react';

export default function OperatorAiRecovery({ brandId, confirmedCosts }: { brandId: string; confirmedCosts: { id: string; usd: string }[] }) {
 return <main className="mx-auto max-w-4xl p-6 text-sm" data-private-profile>
  <Head title="Внутренняя сверка AI"/>
  <h1 className="text-2xl font-bold">Подтверждённая стоимость AI</h1>
  <p className="mt-3 break-all">Бренд: {brandId}</p>
  <p className="mt-2">Показаны только вызовы с подтверждением провайдера.</p>
  {confirmedCosts.length === 0 ? <p className="mt-4">Подтверждённых сумм пока нет.</p> : <ul className="mt-4 space-y-2">{confirmedCosts.map(cost => <li key={cost.id} className="break-all">{cost.id}: {cost.usd} USD</li>)}</ul>}
 </main>;
}
