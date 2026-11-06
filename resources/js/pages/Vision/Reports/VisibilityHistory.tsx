type Point = { measurement_id: string; date: string; visibility: number };

export default function VisibilityHistory({ points }: { points: Point[] }) {
 const width = Math.max(380, points.length * 110);
 const x = (index: number) => 45 + index * (width - 70) / Math.max(1, points.length - 1);
 const y = (value: number) => 120 - value;
 const date = (value: string) => {
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? 'Дата недоступна' : new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short' }).format(parsed);
 };
 return <section className="mt-5 rounded-xl bg-(--color-surface-muted) p-4 text-sm">
  <h3 className="font-semibold">Динамика видимости</h3>
  {points.length < 2 ? <p className="mt-2">Нет двух сопоставимых подтверждённых замеров.</p> : <>
   <p className="mt-2">Подтверждённые снимки до уточнений, с одинаковыми вопросами, моделями и числом прогонов.</p>
   <div className="mt-3 overflow-x-auto"><svg role="img" aria-label="История видимости по датам" viewBox={`0 0 ${width} 150`} style={{ minWidth: width }} className="h-48 w-full">
    {[0,25,50,75,100].map(value=><g key={value}><line x1="45" y1={y(value)} x2={width-25} y2={y(value)} stroke="currentColor" opacity="0.15"/><text x="38" y={y(value)+4} textAnchor="end" fontSize="13" fill="currentColor">{value}%</text></g>)}
    <polyline points={points.map((point,index)=>`${x(index)},${y(point.visibility)}`).join(' ')} fill="none" stroke="var(--color-success-ink)" strokeWidth="3"/>
    {points.map((point,index)=><g key={point.measurement_id}><circle cx={x(index)} cy={y(point.visibility)} r="5" fill="var(--color-success-ink)"/><text x={x(index)} y="142" textAnchor="middle" fontSize="13" fill="currentColor">{date(point.date)}</text></g>)}
   </svg></div>
   <ol className="mt-3 flex flex-wrap gap-x-5 gap-y-2" aria-label="Значения видимости">{points.map(point=><li key={point.measurement_id}>{date(point.date)}: {new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 }).format(point.visibility)}%</li>)}</ol>
  </>}
 </section>;
}
