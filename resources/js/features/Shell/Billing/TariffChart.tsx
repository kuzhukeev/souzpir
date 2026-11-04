export type TariffRow = { date: string; key: string; tariffUnits?: number | null };
const modules = [
  { key: 'seller', name: 'ИИ-продавец', color: 'bg-(--color-action)' },
  { key: 'vision', name: 'Видение', color: 'bg-(--color-warning)' },
  { key: 'ai', name: 'Общее', color: 'bg-(--color-link)' },
];

export function TariffChart({ rows }: { rows: TariffRow[] }) {
  const known = rows.filter(row => row.tariffUnits !== null && row.tariffUnits !== undefined);
  const days = [...new Set(known.map(row => row.date))].map(date => ({
    date,
    parts: modules.map(module => ({ ...module, value: known.filter(row => row.date === date && row.key.startsWith(`${module.key}.`)).reduce((sum, row) => sum + row.tariffUnits!, 0) })),
  }));
  const maximum = Math.max(1, ...days.map(day => day.parts.reduce((sum, part) => sum + part.value, 0)));
  return <section className="mt-5">
    <h3 className="font-bold">Использование по дням</h3>
    <p className="mt-1 text-sm text-(--color-ink-muted)">Единицы тарифа</p>
    {known.length === 0 ? <p role="status" className="mt-4 text-sm">Пересчёт в единицы тарифа пока не настроен. Фактический расход приведён в таблице.</p> : <>
      {known.length !== rows.length && <p className="mt-2 text-sm">График показывает показатели с настроенным пересчётом. Остальные значения доступны в таблице.</p>}
      <ul className="mt-3 flex flex-wrap gap-4 text-sm" aria-label="Модули графика">{modules.map(module => <li key={module.key} className="flex items-center gap-2"><span aria-hidden="true" className={`size-3 rounded ${module.color}`} />{module.name}</li>)}</ul>
      <div className="mt-4 flex items-end gap-4 overflow-x-auto pb-3" role="img" aria-label="Столбцы использования по дням">
        {days.map(day => <div key={day.date} className="flex w-24 shrink-0 flex-col items-center text-center text-sm" data-day={day.date}>
          <div className="flex h-32 w-10 flex-col justify-end">{day.parts.filter(part => part.value > 0).map(part => <div key={part.key} className={`w-full ${part.color}`} style={{ height: `${part.value / maximum * 100}%` }} title={`${part.name}: ${part.value.toLocaleString('ru-RU')} ед.`} data-module={part.key} />)}</div>
          <span className="mt-2">{day.date}</span><span>{day.parts.reduce((sum, part) => sum + part.value, 0).toLocaleString('ru-RU')} ед.</span>
        </div>)}
      </div>
    </>}
  </section>;
}
