import { Head } from '@inertiajs/react';

type Usage = { spentUsd: string; reservedUsd: string; limitUsd: string; percent: number };

function UsageRow({ label, usage }: { label: string; usage: Usage }) {
 return <li>{label}: расход {usage.spentUsd} USD, резерв {usage.reservedUsd} USD, лимит {usage.limitUsd} USD ({usage.percent}%)</li>;
}

export default function OperatorAiRecovery({ brandId, confirmedCosts, budget }: { brandId: string; confirmedCosts: { id: string; usd: string }[]; budget: { daily: Usage; monthly: Usage; organizationMonthly: Usage } }) {
 return <main className="mx-auto max-w-4xl p-6 text-sm" data-private-profile>
  <Head title="Внутренняя сверка AI"/>
  <h1 className="text-2xl font-bold">Подтверждённая стоимость AI</h1>
  <p className="mt-3 break-all">Бренд: {brandId}</p>
  <h2 className="mt-5 text-lg font-semibold">Лимит расходов на ИИ</h2>
  <ul className="mt-2 space-y-1">
   <UsageRow label="Глобально сегодня" usage={budget.daily} />
   <UsageRow label="Глобально за месяц" usage={budget.monthly} />
   <UsageRow label="Организация за месяц" usage={budget.organizationMonthly} />
  </ul>
  <p className="mt-5">Показаны только вызовы с подтверждением провайдера.</p>
  {confirmedCosts.length === 0 ? <p className="mt-4">Подтверждённых сумм пока нет.</p> : <ul className="mt-4 space-y-2">{confirmedCosts.map(cost => <li key={cost.id} className="break-all">{cost.id}: {cost.usd} USD</li>)}</ul>}
 </main>;
}
